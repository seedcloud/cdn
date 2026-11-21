<?php

declare(strict_types=1);

namespace Drupal\seedcloud_cdn;

/**
 * Convierte cache tags de Drupal en el valor de la cabecera CDN-Tag de Bunny y en las tags a purgar.
 *
 * Bunny guarda el valor completo de CDN-Tag como una sola cadena (no lo separa por comas) y purga por
 * patrones con comodines. Formato: `<prefijo>:,all,cfg,<hash>,<hash>,…,` — el prefijo del ambiente una sola
 * vez al inicio y cada tag entre comas. El proxy purga con `<prefijo>:*,<tag>,*`: coincidencia exacta de la
 * tag y anclada al ambiente.
 *
 * El hash debe dar exactamente lo mismo que `short_hash` del proxy (cdk/lambda/cdn-purge/index.py): base36
 * de los primeros 32 bits de SHA-1. Si cambia aquí, cambia allá a la vez (las pruebas de ambos lados usan
 * los mismos valores de referencia).
 */
final class TagHasher {

  /** Bunny lee hasta 1.024 bytes de CDN-Tag; lo demás se trunca y nunca se purgaría. */
  public const HEADER_BUDGET = 1024;

  /** Tag general del ambiente: va en todas las respuestas y se purga en limpiezas completas. */
  public const ALL = 'all';

  /** Todas las tags `config:*` de la respuesta: cambian poco y son la mayoría de las tags de una página. */
  public const CONFIG = 'cfg';

  /** Marca de las respuestas a las que no les cupieron todas sus tags: se purga en cada lote. */
  public const OVERFLOW = 'ovf';

  /** Tags que, al invalidarse, equivalen a «todo cambió» (tema, librerías): purgan la general. */
  private const FULL_PURGE_TAGS = ['rendered', 'library_info'];

  /** Prefijos de tags de poco valor para purgar páginas puntuales: van al final si no caben todas. */
  private const LOW_PRIORITY = ['rendered', 'library_info', 'http_response', 'local_task', 'route_match', '4xx-response', 'user:'];

  public static function shortHash(string $value): string {
    return base_convert(substr(sha1($value), 0, 8), 16, 36);
  }

  public static function prefix(string $project, string $environment): string {
    return self::shortHash($project . ':' . $environment);
  }

  /**
   * Valor de la cabecera CDN-Tag para una respuesta con estas cache tags.
   *
   * @param string[] $tags
   */
  public static function headerValue(string $prefix, array $tags, int $budget = self::HEADER_BUDGET): string {
    $head = $prefix . ':,';
    $entries = [self::ALL => TRUE];
    $hashes = [];
    foreach (self::byPriority($tags) as $tag) {
      if (str_starts_with($tag, 'config:')) {
        $entries[self::CONFIG] = TRUE;
      }
      else {
        $hashes[] = self::shortHash($tag);
      }
    }
    // Siempre queda lugar para la marca de desborde.
    $length = strlen($head) + self::entriesLength($entries) + strlen(self::OVERFLOW) + 1;
    $overflow = FALSE;
    foreach ($hashes as $hash) {
      if (isset($entries[$hash])) {
        continue;
      }
      if ($length + strlen($hash) + 1 > $budget) {
        $overflow = TRUE;
        continue;
      }
      $entries[$hash] = TRUE;
      $length += strlen($hash) + 1;
    }
    if ($overflow) {
      $entries[self::OVERFLOW] = TRUE;
    }
    // Las claves numéricas («123») PHP las guarda como int; implode las devuelve igual.
    return $head . implode(',', array_keys($entries)) . ',';
  }

  /**
   * Tags (sin prefijo, que lo pone el proxy) que hay que purgar por estas invalidaciones.
   *
   * @param string[] $invalidated
   * @return string[]
   */
  public static function purgeTags(array $invalidated): array {
    if (array_intersect($invalidated, self::FULL_PURGE_TAGS)) {
      return [self::ALL];
    }
    $out = [];
    foreach (array_unique($invalidated) as $tag) {
      $out[] = str_starts_with($tag, 'config:') ? self::CONFIG : self::shortHash($tag);
    }
    $out[] = self::OVERFLOW;
    return array_values(array_unique($out));
  }

  /**
   * @param array<string, true> $entries
   */
  private static function entriesLength(array $entries): int {
    return array_sum(array_map(static fn($e): int => strlen((string) $e) + 1, array_keys($entries)));
  }

  /**
   * @param string[] $tags
   * @return string[]
   */
  private static function byPriority(array $tags): array {
    $high = $low = [];
    foreach (array_unique($tags) as $tag) {
      $isLow = FALSE;
      foreach (self::LOW_PRIORITY as $p) {
        if (str_starts_with($tag, $p)) {
          $isLow = TRUE;
          break;
        }
      }
      if ($isLow) {
        $low[] = $tag;
      }
      else {
        $high[] = $tag;
      }
    }
    return array_merge($high, $low);
  }

}
