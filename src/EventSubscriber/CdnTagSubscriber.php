<?php

declare(strict_types=1);

namespace Drupal\seedcloud_cdn\EventSubscriber;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\seedcloud_cdn\Settings;
use Drupal\seedcloud_cdn\TagHasher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Agrega la cabecera CDN-Tag a las respuestas que Bunny puede guardar.
 *
 * Corre después de FinishResponseSubscriber (prioridad 0), que fija Cache-Control. La caché de páginas de
 * Drupal guarda la respuesta con sus cabeceras, así que los aciertos de esa caché también la llevan.
 */
final class CdnTagSubscriber implements EventSubscriberInterface {

  public function __construct(private readonly Settings $settings) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', -100]];
  }

  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest() || !$this->settings->enabled()) {
      return;
    }
    $response = $event->getResponse();
    if (!$response instanceof CacheableResponseInterface || !$response->headers->hasCacheControlDirective('public')) {
      return;
    }
    $tags = $response->getCacheableMetadata()->getCacheTags();
    $response->headers->set('CDN-Tag', TagHasher::headerValue($this->settings->prefix(), $tags));
  }

}
