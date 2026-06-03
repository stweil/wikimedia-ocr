<?php
// phpcs:disable MediaWiki.Commenting.FunctionAnnotations.UnrecognizedAnnotation

declare( strict_types = 1 );

namespace App\Controller;

use App\Engine\TranskribusClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TranskribusController extends AbstractController {

	/**
	 * The main form and result page.
	 * @param TranskribusClient $transkribusClient
	 * @return Response
	 */
	#[Route( '/transkribus', name: 'transkribus' )]
	public function transkribus( TranskribusClient $transkribusClient ): Response {
		return $this->render( 'transkribus.html.twig', [
			'jobs' => $transkribusClient->getJobs(),
		] );
	}
}
