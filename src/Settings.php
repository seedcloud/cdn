<?php

declare(strict_types=1);

namespace Drupal\seedcloud_cdn;

/**
 * Configuración desde las variables de Lagoon. Sin token, proyecto o ambiente el módulo no hace nada
 * (sitios locales o ambientes sin Bunny).
 */
final class Settings {

  public const DEFAULT_URL = 'https://cdn-purge.seedcloud.net/purge-tags';

  public function __construct(
    public readonly string $project,
    public readonly string $environment,
    public readonly string $token,
    public readonly string $purgeUrl = self::DEFAULT_URL,
  ) {}

  public static function fromEnvironment(): self {
    $env = static fn(string $name): string => trim((string) getenv($name));
    return new self(
      $env('LAGOON_PROJECT'),
      $env('LAGOON_ENVIRONMENT') ?: $env('LAGOON_GIT_SAFE_BRANCH'),
      $env('CDN_PURGE_TOKEN'),
      $env('SEEDCLOUD_CDN_PURGE_URL') ?: self::DEFAULT_URL,
    );
  }

  public function enabled(): bool {
    return $this->project !== '' && $this->environment !== '' && $this->token !== '';
  }

  public function prefix(): string {
    return TagHasher::prefix($this->project, $this->environment);
  }

}
