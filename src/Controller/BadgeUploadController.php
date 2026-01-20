<?php

namespace Drupal\conreg\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Returns responses for ConReg - Simple Convention Registration routes.
 */
class BadgeUploadController extends ControllerBase {

  public function __construct(
    protected FileSystemInterface $fileSystem,
    protected FileRepositoryInterface $fileRepository,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Function used for badge uploading.
   */
  public function badgeUpload($eid) {
    $pngData = $this->requestStack->getCurrentRequest()->request->get('data');
    if (!empty($pngData)) {
      [$id, $base64] = explode('|', $pngData);
      [, $data]      = explode(';', $base64);
      [, $data]      = explode(',', $data);
      $pngData       = base64_decode($data);
      $path          = 'public://badges/' . $eid;
      $this->fileSystem->prepareDirectory($path, FileSystemInterface::CREATE_DIRECTORY);
      $this->fileRepository->writeData($pngData, $path . '/' . $id . '.png', FileExists::Replace);
    }

    $content['markup'] = [
      '#markup' => '<p>Badge Upload.</p>',
    ];
    return $content;
  }

}
