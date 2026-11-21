<?php

declare(strict_types=1);

namespace Drupal\seedcloud_cdn\Cache;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\DestructableInterface;
use Drupal\seedcloud_cdn\Settings;
use Drupal\seedcloud_cdn\TagHasher;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Junta las cache tags que Drupal invalida durante la solicitud (o el comando de Drush) y, al terminar,
 * las envía en un solo lote al proxy de purgado. El envío ocurre después de entregar la respuesta, así que
 * no demora al editor; si falla, se registra y el contenido se actualiza al vencer el max-age, como antes.
 */
final class CdnTagPurger implements CacheTagsInvalidatorInterface, DestructableInterface {

  /** @var array<string, true> */
  private array $pending = [];

  public function __construct(
    private readonly Settings $settings,
    private readonly ClientInterface $httpClient,
    private readonly LoggerInterface $logger,
  ) {}

  public function invalidateTags(array $tags): void {
    if (!$this->settings->enabled()) {
      return;
    }
    foreach ($tags as $tag) {
      $this->pending[(string) $tag] = TRUE;
    }
  }

  public function destruct(): void {
    if (!$this->pending) {
      return;
    }
    $invalidated = array_keys($this->pending);
    $this->pending = [];
    $tags = TagHasher::purgeTags($invalidated);
    try {
      $this->httpClient->request('POST', $this->settings->purgeUrl, [
        'json' => ['token' => $this->settings->token, 'environment' => $this->settings->environment, 'tags' => $tags],
        'timeout' => 5,
        'connect_timeout' => 2,
      ]);
    }
    catch (\Throwable $e) {
      // Nunca se registra el token: solo cuántas tags y el error.
      $this->logger->warning('No se pudo purgar @count tags en BunnyCDN: @error', ['@count' => count($tags), '@error' => $e->getMessage()]);
    }
  }

}
