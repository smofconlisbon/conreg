<?php

namespace Drupal\conreg\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for ConReg - Simple Convention Registration routes.
 */
class BadgeUploadController extends ControllerBase {

  public function __construct(
    protected FileSystemInterface $fileSystem,
    protected FileRepositoryInterface $fileRepository,
    protected Request $request,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('file_system'),
      $container->get('file.repository'),
      $container->get('request_stack')->getCurrentRequest()
    );
  }

  /**
   * Function used for badge uploading.
   */
  public function badgeUpload($eid) {
    $pngData = $this->request->request->get('data');
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
