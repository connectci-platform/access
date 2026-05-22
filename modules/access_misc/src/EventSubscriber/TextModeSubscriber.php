<?php

namespace Drupal\access_misc\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Strips HTML when ?text=true is present, returning plain text.
 */
class TextModeSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::RESPONSE][] = ['onResponse', 0];
    return $events;
  }

  /**
   * Strip HTML from the response when ?text=true is requested.
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    if ($request->query->get('text') !== 'true') {
      return;
    }

    $response = $event->getResponse();
    $content_type = $response->headers->get('Content-Type', '');

    // Only process HTML responses.
    if (!str_contains($content_type, 'text/html') && $content_type !== '') {
      return;
    }

    $html = $response->getContent();
    // Remove script and style blocks before stripping tags.
    $html = preg_replace('#<script[^>]*>.*?</script>#si', '', $html);
    $html = preg_replace('#<style[^>]*>.*?</style>#si', '', $html);
    // Decode HTML entities, strip tags, normalize whitespace.
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/^\s*[\r\n]/m', '', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    $text = trim($text);

    $text_response = new Response($text, $response->getStatusCode());
    $text_response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
    $event->setResponse($text_response);
  }

}
