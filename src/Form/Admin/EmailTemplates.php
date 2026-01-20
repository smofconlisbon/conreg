<?php

namespace Drupal\conreg\Form\Admin;

use Drupal\conreg\ConregTokens;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure conreg settings for this site.
 */
class EmailTemplates extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'conreg_email_templates';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'conreg.email_templates',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Get email templates config.
    $config = $this->config('conreg.email_templates');
    if (empty($count = $config->get('count'))) {
      $count = 0;
    }

    // We want to show one more templates than the number saved.
    $count++;

    // Container for templates.
    $form['templates'] = [
      '#prefix' => '<div id="templates">',
      '#suffix' => '</div>',
      '#tree' => TRUE,
    ];

    for ($template = 1; $template <= $count; $template++) {
      /*
       * Fields for email templates.
       */

      $form['templates']['template' . $template] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Email template @template', ['@template' => $template]),
        '#tree' => TRUE,
      ];

      $form['templates']['template' . $template]['subject'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subject'),
        '#default_value' => $config->get('template' . $template . 'subject'),
      ];

      $form['templates']['template' . $template]['body'] = [
        '#type' => 'text_format',
        '#title' => $this->t('Body'),
        '#description' => $this->t('Text for the email body. you may use the following tokens: @tokens.', ['@tokens' => ConregTokens::tokenHelp()]),
        '#default_value' => $config->get('template' . $template . 'body'),
        '#format' => $config->get('template' . $template . 'format'),
      ];
    }

    // Make sure last template is blank.
    $form['templates']['template' . $count]['subject']['#default_value'] = '';
    $form['templates']['template' . $count]['body']['#default_value'] = '';

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $vals = $form_state->getValues();

    $config = $this->config('conreg.email_templates');
    $count = 0;
    foreach ($vals['templates'] as $template) {
      if (!empty($template['subject']) || !empty($template['body']['value'])) {
        $count++;
        $config->set('template' . $count . 'subject', $template['subject']);
        $config->set('template' . $count . 'body', $template['body']['value']);
        $config->set('template' . $count . 'format', $template['body']['format']);
      }
    }
    $config->set('count', $count);
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
