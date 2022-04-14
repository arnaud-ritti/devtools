<?php

declare(strict_types=1);

namespace App\Controller\Http;

use App\Dto\RequestBin;
use App\Manager\BinManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BinController extends AbstractController
{
    public function __construct(private BinManager $binManager)
    {
    }

    #[Route(
        '/{anything}',
        name: 'api.bin.subdomain',
        requirements: ['anything' => "([a-zA-Z0-9_-]+\/?)*"],
        defaults: ['anything' => ''],
        condition: 'request.attributes.has("_bin") and (env("BUCKET_MODE") == "subdomain" || env("BUCKET_MODE") == "both")',
        priority: 0
    )]
    #[Route(
        '/b/{anything}',
        name: 'api.bin.path',
        requirements: ['anything' => "([a-zA-Z0-9_-]+\/?)*"],
        defaults: ['anything' => ''],
        condition: 'request.attributes.has("_bin") and (env("BUCKET_MODE") == "path" || env("BUCKET_MODE") == "both")',
        priority: 0
    )]
    public function bin(Request $request): Response
    {
        $this->binManager->setCurrentBin($request->attributes->get('_bin'));
        if ($request->getContentType() === 'json' && $request->getContent()) {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $request->request->replace(is_array($data) ? $data : []);
        }

        $requestBinId = $this->binManager->saveRequest(RequestBin::createFromRequest($request));
        $response = $this->json('OK');
        $response->headers->add([
            'X-Inspect-Link' => sprintf('https://%s%s#%s', $this->getParameter('base_host'), $this->generateUrl('inspect', [
                'bin' => (string) $this->binManager->getCurrentBin(),
            ]), $requestBinId),
        ]);
        return $response;
    }
}