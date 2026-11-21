<?php

declare(strict_types=1);

namespace Drupal\Tests\seedcloud_cdn\Unit;

use Drupal\seedcloud_cdn\TagHasher;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\seedcloud_cdn\TagHasher
 */
final class TagHasherTest extends TestCase {

  /** Mismos valores que test_index.py del proxy: el hash debe coincidir en ambos lados. */
  public function testReferenceValues(): void {
    $this->assertSame('189o325', TagHasher::shortHash('portal-catolicismo:master'));
    $this->assertSame('w803wa', TagHasher::shortHash('node:1'));
    $this->assertSame('6y3g7l', TagHasher::shortHash('node_list'));
    $this->assertSame('189o325', TagHasher::prefix('portal-catolicismo', 'master'));
  }

  public function testHeaderFormat(): void {
    $value = TagHasher::headerValue('pfx', ['node:1', 'node_list', 'node:1']);
    $this->assertSame('pfx:,all,w803wa,6y3g7l,', $value);
  }

  public function testConfigTagsCollapse(): void {
    $value = TagHasher::headerValue('pfx', ['config:system.site', 'node:1', 'config:block.block.x']);
    $this->assertSame('pfx:,all,cfg,w803wa,', $value);
  }

  /** Página real típica: ~140 tags config y ~100 de contenido caben sin desbordar. */
  public function testTypicalPageFits(): void {
    $tags = array_merge(
      array_map(static fn(int $i): string => "config:block.block.b$i", range(1, 140)),
      array_map(static fn(int $i): string => "paragraph:$i", range(1, 100)),
    );
    $value = TagHasher::headerValue('1w8zk5w', $tags);
    $this->assertLessThanOrEqual(TagHasher::HEADER_BUDGET, strlen($value));
    $this->assertStringNotContainsString(',ovf,', $value);
    $this->assertStringContainsString(',' . TagHasher::shortHash('paragraph:100') . ',', $value);
  }

  public function testHeaderFitsBudgetAndMarksOverflow(): void {
    $tags = array_map(static fn(int $i): string => "node:$i", range(1, 300));
    $value = TagHasher::headerValue('189o325', array_merge(['user:1'], $tags));
    $this->assertLessThanOrEqual(TagHasher::HEADER_BUDGET, strlen($value));
    $this->assertStringEndsWith(',ovf,', $value);
    $this->assertStringStartsWith('189o325:,all,', $value);
    // Las tags de contenido van antes que las de poca prioridad.
    $this->assertStringNotContainsString(',' . TagHasher::shortHash('user:1') . ',', $value);
  }

  public function testPurgeTags(): void {
    $this->assertSame(['w803wa', 'ovf'], TagHasher::purgeTags(['node:1', 'node:1']));
    $this->assertSame(['cfg', 'w803wa', 'ovf'], TagHasher::purgeTags(['config:system.site', 'config:x', 'node:1']));
    $this->assertSame(['all'], TagHasher::purgeTags(['node:1', 'rendered']));
  }

}
