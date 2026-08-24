<?php

declare(strict_types=1);

namespace Drupal\conreg\Trait;

/**
 * Trait to add a "Browse available tokens" link to a form.
 */
trait TokenTreeLinkTrait {

  /**
   * Builds a token_tree_link render element (provided by the Token module).
   *
   * @param string[] $token_types
   *   Token types to show in the tree. Defaults to just conreg's own.
   */
  protected function tokenTreeLink(array $token_types = ['conreg']): array {
    return [
      '#theme' => 'token_tree_link',
      '#token_types' => $token_types,
    ];
  }

}
