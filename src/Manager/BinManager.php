<?php

declare(strict_types=1);

namespace App\Manager;

use App\DBAL\BinConnectionWrapper;
use App\Dto\Bin;
use App\Dto\RequestBin;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

class BinManager
{
    private ?Bin $currentBin = null;
    private BinConnectionWrapper $binConnectionWrapper;

    public function __construct(
        private ManagerRegistry $managerRegistry,
        private ParameterBagInterface $parameterBag
    ) {
        $this->binConnectionWrapper = $this->managerRegistry->getConnection('bin');
    }

    public function getAllBin(): Finder
    {
        return (new Finder())
            ->files()
            ->name('*.db')
            ->notName('*.db.lock')
            ->in($this->parameterBag->get('buckets_dirs'))
        ;
    }

    public function getBinUrls(): array
    {
        $request = Request::createFromGlobals();
        $scheme = $request->getScheme();
        $host = $request->getHttpHost();
        $subdomain = sprintf('%s://%s.%s', $scheme, (string) $this->getCurrentBin(), $host);
        $path = sprintf('%s://%s/b/%s', $scheme, $host, (string) $this->getCurrentBin());
        $bucketMode = $this->parameterBag->get('buckets_mode');

        if (filter_var($request->getHost(), FILTER_VALIDATE_IP) && 'both' === $bucketMode) {
            $bucketMode = 'path';
        }

        return match ($bucketMode) {
            'both' => [$subdomain, $path],
            'subdomain' => [$subdomain],
            default => [$path]
        };
    }

    public function getMax(): int
    {
        return (int) $this->parameterBag->get('buckets_request_limit');
    }

    public function getCurrentBin(): ?Bin
    {
        return $this->currentBin;
    }

    public function setBinByUuid(string $uuid): void
    {
        $this->setCurrentBin(new Bin($uuid));
    }

    public function setCurrentBin(Bin $currentBin): void
    {
        $this->binConnectionWrapper->selectBin($currentBin);
        $this->currentBin = $currentBin;
    }

    public function getConnection(): BinConnectionWrapper
    {
        $this->binConnectionWrapper->selectBin($this->getCurrentBin());

        return $this->binConnectionWrapper;
    }

    public function createBin(): Bin
    {
        $bin = new Bin(Uuid::v4());
        $this->setCurrentBin($bin);

        return $bin;
    }

    public function deleteBin(): void
    {
        $this->binConnectionWrapper->dropBin($this->getCurrentBin());
    }

    /**
     * @throws Exception
     */
    public function getRequests(): ArrayCollection
    {
        $qb = $this->binConnectionWrapper->createQueryBuilder();
        $rows = $qb->select('*')
            ->from('requests')
            ->orderBy('date', 'DESC')
            ->fetchAllAssociative();

        $results = new ArrayCollection();
        foreach ($rows as $row) {
            $results->add(RequestBin::createFromArray($row));
        }

        return $results;
    }

    public function isExpired(): bool
    {
        $qb = $this->binConnectionWrapper->createQueryBuilder();
        $expiration = (int) $this->parameterBag->get('buckets_expire_after');

        $hasExpiredRequest = (bool) $qb->select('id')
            ->from('requests')
            ->orderBy('date', 'ASC')
            ->where('date <= :date')
            ->setParameter('date', new DateTime(sprintf('-%s hour', $expiration)), Types::DATETIME_MUTABLE)
            ->setMaxResults(1)
            ->fetchOne();

        $hasExpiredLock = false;
        $binFile = sprintf('%s/%s.db', $this->parameterBag->get('buckets_dirs'), $this->currentBin->getId());
        if (file_exists($binFile.'.lock')) {
            $fileStat = stat($binFile.'.lock');
            $hasExpiredLock = (new DateTime('@'.$fileStat['mtime'])) <= (new DateTime(sprintf('-%s hour', $expiration)));
        }

        return $hasExpiredRequest || $hasExpiredLock;
    }

    /**
     * @throws Exception
     */
    public function saveRequest(RequestBin $request): string
    {
        $qb = $this->binConnectionWrapper->createQueryBuilder();
        $id = Uuid::v4()->toRfc4122();
        $qb->insert('requests')
            ->values([
                'id' => ':id',
                'method' => ':method',
                'origins' => ':origins',
                'content_type' => ':content_type',
                'content_length' => ':content_length',
                'host' => ':host',
                'path' => ':path',
                'query_args' => ':query_args',
                'headers' => ':headers',
                'raw_body' => ':raw_body',
                'body' => ':body',
            ])
            ->setParameter('id', $id)
            ->setParameter('method', $request->method)
            ->setParameter('origins', $request->origins, Type::getType(Types::ARRAY))
            ->setParameter('content_type', $request->contentType)
            ->setParameter('content_length', $request->contentLength)
            ->setParameter('host', $request->host)
            ->setParameter('path', $request->path)
            ->setParameter('query_args', $request->queryArgs, Type::getType(Types::ARRAY))
            ->setParameter('headers', $request->headers, Type::getType(Types::ARRAY))
            ->setParameter('raw_body', $request->rawBody)
            ->setParameter('body', $request->body, Type::getType(Types::ARRAY))
            ->executeQuery();

        $this->removeOverfilled();

        return $id;
    }

    public function removeOverfilled(): void
    {
        foreach ($this->getRequests()->slice($this->getMax()) as $remove) {
            $this->binConnectionWrapper->delete('requests', ['id' => $remove->id]);
        }
    }
}
