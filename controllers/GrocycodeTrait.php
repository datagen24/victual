<?php

namespace Victual\Controllers;

use Victual\Helpers\Grocycode;
use jucksearm\barcode\lib\BarcodeFactory;
use jucksearm\barcode\lib\DatamatrixFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Shared helper for controllers that serve Grocycode (grocy's own barcode)
 * images for their entities (products, stock entries, chores, batteries, recipes).
 */
trait GrocycodeTrait
{
	/**
	 * Renders the given Grocycode as a PNG (DataMatrix when VICTUAL_GROCYCODE_TYPE is '2D',
	 * otherwise Code 128) and writes it to the response.
	 *
	 * Query parameters: size (image size in pixels 2D / bar height 1D; renderer default when omitted)
	 * and download (truthy value serves the PNG as a file attachment instead of inline).
	 *
	 * @return Response The response with PNG body and appropriate content headers
	 */
	public function ServeGrocycodeImage(Request $request, Response $response, Grocycode $grocycode)
	{
		$queryParams = $request->getQueryParams();
		$size = $queryParams['size'] ?? null;

		if (VICTUAL_GROCYCODE_TYPE == '2D')
		{
			$png = (new DatamatrixFactory())->setCode((string)$grocycode)->setSize($size)->getDatamatrixPngData();
		}
		else
		{
			$png = (new BarcodeFactory())->setType('C128')->setCode((string)$grocycode)->setHeight($size)->getBarcodePngData();
		}

		$isDownload = $queryParams['download'] ?? false;
		if ($isDownload)
		{
			$response = $response->withHeader('Content-Type', 'application/octet-stream')
				->withHeader('Content-Disposition', 'attachment; filename=Grocycode.png')
				->withHeader('Content-Length', strlen($png))
				->withHeader('Cache-Control', 'no-cache')
				->withHeader('Last-Modified', gmdate('D, d M Y H:i:s') . ' GMT');
		}
		else
		{
			$response = $response->withHeader('Content-Type', 'image/png')
				->withHeader('Content-Length', strlen($png))
				->withHeader('Cache-Control', 'no-cache')
				->withHeader('Last-Modified', gmdate('D, d M Y H:i:s') . ' GMT');
		}
		$response->getBody()->write($png);
		return $response;
	}
}
