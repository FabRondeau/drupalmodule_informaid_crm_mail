<?php

declare(strict_types=1);

namespace Drupal\informaid_crm_mail\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Render\Markup;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Component\Datetime\Time;

/**
 * Provides a informaid_crm_mail form.
 *
 * @todo add token feature.
 */
final class CampaignForm extends FormBase {

  const CAMPAIGN_TABLE = 'inf_crm_mail_campaign';
  const MAILS_TABLE = 'inf_crm_mail_mails';

  /**
   * EntityTypeManager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Connection service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;
  /**
   * Mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;


  /**
   * Request stack.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * Date time.
   *
   * @var \Drupal\Component\Datetime\Time
   */
  protected $dateTime;

  public function __construct(
    protected EntityTypeManagerInterface $entitytype_manager,
    protected Connection $database,
    protected MailManagerInterface $mail_manager,
    protected RequestStack $request_stack,
    protected Time $time,
  ) {
    $this->entityTypeManager = $entitytype_manager;
    $this->connection = $database;
    $this->mailManager = $mail_manager;
    $this->request = $request_stack->getCurrentRequest();
    $this->dateTime = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('Drupal\Core\Mail\MailManagerInterface'),
      $container->get('request_stack'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'informaid_crm_mail_campaign';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {

    $aliasesValues = $this->getAliasesvalues();
    $emailsModes = $this->getMethodDetails();

    $form['#attached']['library'][] = 'informaid/informaid-crm-mail-form';

    $form['mail_campaign_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mailing campaign title'),
      '#required' => TRUE,
    ];

    $form['mail_contacts_aliases'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Aliases'),
      '#description' => $this->t('Contacts who get one of those aliases will be choosen.'),
      '#default_value' => [],
      '#required' => TRUE,
      '#options' => $aliasesValues,
    ];

    $form['mail_contacts_detail'] = [
      '#type' => 'radios',
      '#title' => $this->t('Emails modes'),
      '#description' => $this->t('Contacts who get one of those aliases will be choosen.'),
      '#default_value' => [],
      '#required' => TRUE,
      '#options' => $emailsModes,
    ];

    $form['mail_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mail subject'),
      '#required' => TRUE,
    ];

    $form['mail_body'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Message'),
      '#required' => TRUE,
    ];

    $form['add_target_infos'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Add target infos'),
      '#description' => $this->t('Add target infos to the bottom of the body: firm name, firme wbesite.'),
      '#required' => FALSE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Send the emails'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // @todo Validate the form here.
    // Example:
    // @code
    //   if (mb_strlen($form_state->getValue('message')) < 10) {
    //     $form_state->setErrorByName(
    //       'message',
    //       $this->t('Message should be at least 10 characters.'),
    //     );
    //   }
    // @endcode
  }

  /**
   * {@inheritdoc}
   *
   * @todo format each mail.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $time = $this->dateTime->getCurrentTime();
    // Create the campaign.
    $campaign_id = $this->createThecampaign($time, $form_state);
    $contactsIdsAndMails = $this->getContactsIdsAndMails($form_state);
    $results = $this->sendEmails($contactsIdsAndMails, $form_state);

    // Create the history.
    foreach ($results as $result) {
      $this->createTheEmailHistory(\intval($campaign_id), $result);
    }

    foreach ($results as $result) {
      if (!empty($result['result'])
        && !empty($result['result']['send'])
      && $result['result']['send']
      ) {
        $this->messenger()->addStatus($this->t(
          'The message to @to has been sent.', [
            '@to' => $result['email'],
          ]));
      }
      else {
        $this->messenger()->addError($this->t(
          'The message to @to has not been sent.', [
            '@to' => $result['email'],
          ]));
      }
    }

  }

  /**
   * Send emails.
   *
   * @param mixed $contactsIdsAndMails
   *   Contacts ids and emails.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return array
   *   array with 'email' and 'result' keys.
   */
  private function sendEmails(array $contactsIdsAndMails, FormStateInterface $form_state): array {
    $subject = $form_state->getvalue('mail_subject');
    $body = $form_state->getvalue('mail_body');
    $addTargetInfos = $form_state->getvalue('add_target_infos');
    $campaign = $form_state->getValue('mail_campaign_label');
    $campaignSanitized = str_replace([' ', 'à', 'ù', 'é', 'ê'], '_', $campaign);
    $langcode = $this->currentUser()->getPreferredLangcode();
    $params['subject'] = $subject;

    $params['headers'] = [
      'From' => 'fabienne.rondeau@informaid.fr',
    ];
    $returns = [];
    foreach ($contactsIdsAndMails as $target) {
      $full_body = $body['value'] ?? '';
      if ($addTargetInfos == TRUE) {
        $full_body .= '<p></p>';
        $full_body .= '<p>---------------------------------</p>';
        $full_body .= '<p>Vos informations:</p>';
        $full_body .= '<p>';
        $full_body .= 'Société: ' . $target->cc2_name . '<br>';
        $full_body .= 'Site web: ' . $target->field_website_url_uri;
        $full_body .= '</p>';
      }

      $params['body'] = [
      // Adapte le texte en HTML.
        Markup::create(Html::transformRootRelativeUrlsToAbsolute((string) $full_body, $this->request->getSchemeAndHttpHost())),
      ];
      $returns[$target->crm_contact] = [
        'email' => $target->email_value,
        'result' => $this->mailManager->mail(
          'informaid_crm_mail',
          $campaignSanitized,
          sprintf('%s <%s>', $target->name, $target->email_value),
          $langcode,
          $params,
        ),
        'time' => $this->dateTime->getCurrentTime(),
        'contact' => $target,
      ];

    }
    return $returns;

  }

  /**
   * Create the campaign.
   */
  private function createThecampaign(int $time, FormStateInterface $form_state): string|FALSE {
    $choosenAliases = array_filter($form_state->getvalue('mail_contacts_aliases'));
    $fields = [
      'uid' => $this->currentUser()->id(),
      'label' => $form_state->getvalue('mail_campaign_label'),
      'aliases' => trim(implode(',', $choosenAliases)),
      'detail' => $form_state->getvalue('mail_contacts_detail'),
      'subject' => $form_state->getvalue('mail_subject'),
      'body' => $form_state->getvalue('mail_body')['value'] ?? '',
      'created' => $time,
    ];
    try {
      return $this->connection->insert($this::CAMPAIGN_TABLE)
        ->fields($fields)
        ->execute();

    }
    catch (\Exception $e) {
      $this->messenger()->addMessage($this->t('Campaign insertion failed. Message = %message', [
        '%message' => $e->getMessage(),
      ]), 'error');
      return FALSE;
    }

  }

  /**
   * Create the email history.
   */
  private function createTheEmailHistory(int $campaign_id, array $result): int | NULL | string | FALSE {
    $body = '';
    foreach ($result['result']['params']['body'] as $b) {
      $body .= $b->__toString();
    }
    $fields = [
      'campaign_id' => $campaign_id,
      'contact_id' => $result['contact']->crm_contact,
      'contact_email' => $result['email'],
      'contact_name' => $result['contact']->name,
      'contact_bundle' => $result['contact']->bundle,
      'subject' => $result['result']['subject'],
      'body' => $body,
      'created' => $result['time'],
      'sent' => $result['result']['send'],
    ];
    try {
      return $this->connection->insert($this::MAILS_TABLE)
        ->fields($fields)
        ->execute();

    }
    catch (\Exception $e) {
      $this->messenger()->addMessage($this->t('Campaign insertion failed. Message = %message', [
        '%message' => $e->getMessage(),
      ]), 'error');
      return FALSE;
    }
  }

  /**
   * Get method details.
   */
  private function getMethodDetails() {
    try {
      $query = $this->connection->select('crm_contact_method', 'ccm')
        ->fields('ccm', ['detail'])
        ->groupBy('ccm.detail');
      $result = $query->execute()->fetchAll();
      $return = [];
      foreach ($result as $r) {
        $return[$r->detail] = ucfirst($r->detail);
      }
      return $return;
    }
    catch (\Exception $e) {
      $this->messenger()->addMessage($this->t('Method details failed. Message = %message', [
        '%message' => $e->getMessage(),
      ]), 'error');
      return FALSE;
    }
  }

  /**
   * Get Contacts Ids.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return array
   *   Array of contacts objects withs properties like:
   *   - name,
   *   - status,
   *   - crm_contact,
   *   - email_value,
   *   - contacts_target_id,
   *   - delta,
   *   - cc2_name,
   *   - cc2_status
   *   - field_website_url_uri
   *   - field_website_url_title
   */
  private function getContactsIdsAndMails(FormStateInterface $form_state): array|bool {
    $targets = $form_state->getvalue('mail_contacts_aliases');
    $detail = $form_state->getvalue('mail_contacts_detail');
    try {
      $query = $this->connection->select('crm_contact__aliases', 'cca');
      $query->innerJoin('crm_contact', 'cc', 'cc.id=cca.entity_id');
      $query->innerJoin('crm_contact_method', 'ccm', 'ccm.crm_contact=cca.entity_id');
      $query->innerJoin('crm_contact_method__email', 'ccme', 'ccme.entity_id = ccm.id');
      $query->innerJoin('crm_relationship__contacts', 'crc1', '(crc1.contacts_target_id = ccm.crm_contact and crc1.delta = 0)');
      $query->innerJoin('crm_relationship__contacts', 'crc2', '(crc2.entity_id = crc1.entity_id and crc2.delta = 1)');
      $query->innerJoin('crm_contact', 'cc2', 'cc2.id=crc2.contacts_target_id');
      $query->leftJoin('crm_contact__field_website_url', 'ccfwu', 'ccfwu.entity_id=cc2.id');

      $query->fields('cc', ['name', 'status']);
      $query->fields('ccm', ['crm_contact']);
      $query->fields('cca', ['bundle']);
      $query->fields('ccme', ['email_value']);
      $query->fields('crc2', ['contacts_target_id', 'delta']);
      $query->fields('cc2', ['name', 'status']);
      $query->fields('ccfwu', ['field_website_url_uri', 'field_website_url_title']);

      $query->condition('cc.status', 1);
      $query->condition('cca.bundle', 'person');

      // Because we send an email.
      $query->condition('ccm.type', 'email');

      // Because emails are targeted on detail.
      $query->condition('ccm.detail', $detail);
      $query->condition('ccm.status', 1);

      $conditions = $query->orConditionGroup();
      foreach ($targets as $target) {
        $conditions->condition('cca.aliases_value', $target);
      }
      $query->condition($conditions);
      return $query->execute()->fetchAll();

    }
    catch (\Exception $e) {
      $this->messenger()->addMessage($this->t('Contacts selection failed. Message = %message', [
        '%message' => $e->getMessage(),
      ]), 'error');
      return FALSE;
    }

  }

  /**
   * Get Aliases values.
   *
   * @returna array.
   */
  private function getAliasesvalues() {
    $contacts = $this->entityTypeManager->getStorage('crm_contact')->loadMultiple();
    $keys = [];
    $i = 0;
    foreach ($contacts as $contact) {
      foreach ($contact->aliases as $alias) {
        if (!empty($alias->value) && !isset($key[$alias->value])) {
          $keys[$alias->value] = $alias->value;
          $i++;
        }
      }
    }
    return $keys;

  }

}
