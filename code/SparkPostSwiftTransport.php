<?php
namespace LeKoala\SparkPost;

use Exception;
use \Swift_MimePart;
use \Swift_Transport;
use \Swift_Attachment;
use \Swift_Mime_Message;
use \Swift_Events_SendEvent;
use Psr\Log\LoggerInterface;
use \Swift_Events_EventListener;
use \Swift_Events_EventDispatcher;
use SilverStripe\Control\Director;
use SilverStripe\Assets\FileNameFilter;
use SilverStripe\Core\Injector\Injector;
use LeKoala\SparkPost\Api\SparkPostApiClient;

/**
 * A SparkPost transport for Swift Mailer using our custom client
 *
 * Heavily inspired by slowprog/SparkPostSwiftMailer
 *
 * @link https://github.com/slowprog/SparkPostSwiftMailer
 * @link https://www.sparkpost.com/api#/reference/introduction
 * @author LeKoala <thomas@lekoala.be>
 */
class SparkPostSwiftTransport implements Swift_Transport
{

    /**
     * @var Swift_Transport_SimpleMailInvoker
     */
    protected $invoker;

    /**
     * @var Swift_Events_SimpleEventDispatcher
     */
    protected $eventDispatcher;

    /**
     * @var LeKoala\SparkPost\Api\SparkPostApiClient
     */
    protected $client;

    /**
     * @var array
     */
    protected $resultApi;

    /**
     * @var string
     */
    protected $fromEmail;

    /**
     * @var boolean
     */
    protected $isStarted = false;

    public function __construct(SparkPostApiClient $client)
    {
        $this->client = $client;

        $this->invoker = new \Swift_Transport_SimpleMailInvoker();
        $this->eventDispatcher = new \Swift_Events_SimpleEventDispatcher();
    }

    /**
     * Not used
     */
    public function isStarted()
    {
        return $this->isStarted;
    }

    /**
     * Not used
     */
    public function start()
    {
        $this->isStarted = true;
    }

    /**
     * Not used
     */
    public function stop()
    {
        $this->isStarted = false;
    }

    /**
     * @param Swift_Mime_Message $message
     * @param null $failedRecipients
     * @return int Number of messages sent
     */
    public function send(Swift_Mime_Message $message, &$failedRecipients = null)
    {
        $this->resultApi = null;
        if ($event = $this->eventDispatcher->createSendEvent($this, $message)) {
            $this->eventDispatcher->dispatchEvent($event, 'beforeSendPerformed');
            if ($event->bubbleCancelled()) {
                return 0;
            }
        }

        $sendCount = 0;
        $disableSending = $message->getHeaders()->has('X-SendingDisabled') || SparkPostHelper::config()->disable_sending;

        $transmissionData = $this->getTransmissionFromMessage($message);

        /* @var $client LeKoala\SparkPost\Api\SparkPostApiClient */
        $client = $this->client;

        if ($disableSending) {
            $result = [
                'total_rejected_recipients' => 0,
                'total_accepted_recipients' => 0,
                'id' => microtime(),
                'disabled' => true,
            ];
        } else {
            $result = $client->createTransmission($transmissionData);
        }
        $this->resultApi = $result;

        if (SparkPostHelper::config()->enable_logging) {
            $this->logMessageContent($message, $result);
        }

        $sendCount = $this->resultApi['total_accepted_recipients'];

        // TODO: might not be the best way to return fromEmail
        if ($this->resultApi['total_rejected_recipients'] > 0) {
            $failedRecipients[] = $this->fromEmail;
        }

        if ($event) {
            if ($sendCount > 0) {
                $event->setResult(Swift_Events_SendEvent::RESULT_SUCCESS);
            } else {
                $event->setResult(Swift_Events_SendEvent::RESULT_FAILED);
            }

            $this->eventDispatcher->dispatchEvent($event, 'sendPerformed');
        }

        return $sendCount;
    }

    /**
     * Log message content
     *
     * @param Swift_Mime_Message $message
     * @param array $results Results from the api
     * @return void
     */
    protected function logMessageContent(Swift_Mime_Message $message, $results = [])
    {
        $subject = $message->getSubject();
        $body = $message->getBody();
        $contentType = $this->getMessagePrimaryContentType($message);

        $logContent = $body;

        // Append some extra information at the end
        $logContent .= '<hr><pre>Debug infos:' . "\n\n";
        $logContent .= 'To : ' . print_r($message->getTo(), true) . "\n";
        $logContent .= 'Subject : ' . $subject . "\n";
        $logContent .= 'From : ' . print_r($message->getFrom(), true) . "\n";
        $logContent .= 'Headers:' . "\n";
        foreach ($message->getHeaders()->getAll() as $header) {
            $logContent .= '  ' . $header->getFieldName() . ': ' . $header->getFieldBody() . "\n";
        }
        if (!empty($params['recipients'])) {
            $logContent .= 'Recipients : ' . print_r($message->getTo(), true) . "\n";
        }
        $logContent .= 'Results:' . "\n";
        foreach ($results as $resultKey => $resultValue) {
            $logContent .= '  ' . $resultKey . ': ' . $resultValue . "\n";
        }
        $logContent .= '</pre>';

        $logFolder = SparkPostHelper::getLogFolder();

        // Generate filename
        $filter = new FileNameFilter();
        $title = substr($filter->filter($subject), 0, 35);
        $logName = date('Ymd_His') . '_' . $title;

        // Store attachments if any
        $attachments = $message->getChildren();
        if (!empty($attachments)) {
            $logContent .= '<hr />';
            foreach ($attachments as $attachment) {
                if ($attachment instanceof Swift_Attachment) {
                    file_put_contents($logFolder . '/' . $logName . '_' . $attachment->getFilename(), $attachment->getBody());
                    $logContent .= 'File : ' . $attachment->getFilename() . '<br/>';
                }
            }
        }

        // Store it
        $ext = ($contentType == 'text/html') ? 'html' : 'txt';
        $r = file_put_contents($logFolder . '/' . $logName . '.' . $ext, $logContent);

        if (!$r && Director::isDev()) {
            throw new Exception('Failed to store email in ' . $logFolder);
        }
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return Injector::inst()->get(LoggerInterface::class)->withName('SparkPost');
    }

    /**
     * @param Swift_Events_EventListener $plugin
     */
    public function registerPlugin(Swift_Events_EventListener $plugin)
    {
        $this->eventDispatcher->bindEventListener($plugin);
    }

    /**
     * @return array
     */
    protected function getSupportedContentTypes()
    {
        return array(
            'text/plain',
            'text/html'
        );
    }

    /**
     * @param string $contentType
     * @return bool
     */
    protected function supportsContentType($contentType)
    {
        return in_array($contentType, $this->getSupportedContentTypes());
    }

    /**
     * @param Swift_Mime_Message $message
     * @return string
     */
    protected function getMessagePrimaryContentType(Swift_Mime_Message $message)
    {
        $contentType = $message->getContentType();

        if ($this->supportsContentType($contentType)) {
            return $contentType;
        }

        // SwiftMailer hides the content type set in the constructor of Swift_Mime_Message as soon
        // as you add another part to the message. We need to access the protected property
        // _userContentType to get the original type.
        $messageRef = new \ReflectionClass($message);
        if ($messageRef->hasProperty('_userContentType')) {
            $propRef = $messageRef->getProperty('_userContentType');
            $propRef->setAccessible(true);
            $contentType = $propRef->getValue($message);
        }

        return $contentType;
    }

    /**
     * Convert a Swift Message to a transmission
     *
     * @param Swift_Mime_Message $message
     * @return array SparkPost Send Message
     * @throws \Swift_SwiftException
     */
    public function getTransmissionFromMessage(Swift_Mime_Message $message)
    {
        $contentType = $this->getMessagePrimaryContentType($message);

        $fromAddresses = $message->getFrom();
        $fromEmails = array_keys($fromAddresses);
        list($fromFirstEmail, $fromFirstName) = each($fromAddresses);
        $this->fromEmail = $fromFirstEmail;

        $toAddresses = $message->getTo();
        $ccAddresses = $message->getCc() ? $message->getCc() : [];
        $bccAddresses = $message->getBcc() ? $message->getBcc() : [];
        $replyToAddresses = $message->getReplyTo() ? $message->getReplyTo() : [];

        $recipients = array();
        $cc = array();
        $bcc = array();
        $attachments = array();
        $headers = array();
        $tags = array();
        $inlineCss = null;

        // Mandrill compatibility
        // @link https://mandrill.zendesk.com/hc/en-us/articles/205582467-How-to-Use-Tags-in-Mandrill
        if ($message->getHeaders()->has('X-MC-Tags')) {
            /** @var \Swift_Mime_Headers_UnstructuredHeader $tagsHeader */
            $tagsHeader = $message->getHeaders()->get('X-MC-Tags');
            $tags = explode(',', $tagsHeader->getValue());
        }
        if ($message->getHeaders()->has('X-MC-InlineCSS')) {
            $inlineCss = $message->getHeaders()->get('X-MC-InlineCSS')->getValue();
        }

        // Handle MSYS headers
        // @link https://developers.sparkpost.com/api/smtp-api.html
        if ($message->getHeaders()->has('X-MSYS-API')) {
            $msysHeader = json_decode($message->getHeaders()->get('X-MSYS-API')->getValue(), JSON_OBJECT_AS_ARRAY);
            if (!empty($msysHeader['tags'])) {
                $tags = array_merge($tags, $msysHeader['tags']);
            }
        }

        // Build recipients list
        // @link https://developers.sparkpost.com/api/recipient-lists.html
        foreach ($toAddresses as $toEmail => $toName) {
            $recipients[] = array(
                'address' => array(
                    'email' => $toEmail,
                    'name' => $toName,
                ),
                'tags' => $tags,
            );
        }

        $reply_to = null;
        foreach ($replyToAddresses as $replyToEmail => $replyToName) {
            if ($replyToName) {
                $reply_to = sprintf('%s <%s>', $replyToName, $replyToEmail);
            } else {
                $reply_to = $replyToEmail;
            }
        }

        foreach ($ccAddresses as $ccEmail => $ccName) {
            $cc[] = array(
                'email' => $ccEmail,
                'name' => $ccName,
            );
        }

        foreach ($bccAddresses as $bccEmail => $bccName) {
            $bcc[] = array(
                'email' => $bccEmail,
                'name' => $bccName,
            );
        }

        $bodyHtml = $bodyText = null;

        if ($contentType === 'text/plain') {
            $bodyText = $message->getBody();
        } elseif ($contentType === 'text/html') {
            $bodyHtml = $message->getBody();
        } else {
            $bodyHtml = $message->getBody();
        }

        foreach ($message->getChildren() as $child) {
            if ($child instanceof Swift_Attachment) {
                $attachments[] = array(
                    'type' => $child->getContentType(),
                    'name' => $child->getFilename(),
                    'data' => base64_encode($child->getBody())
                );
            } elseif ($child instanceof Swift_MimePart && $this->supportsContentType($child->getContentType())) {
                if ($child->getContentType() == "text/html") {
                    $bodyHtml = $child->getBody();
                } elseif ($child->getContentType() == "text/plain") {
                    $bodyText = $child->getBody();
                }
            }
        }


        // If we ask to provide plain, use our custom method instead of the provided one
        if ($bodyHtml && SparkPostHelper::config()->provide_plain) {
            $bodyText = EmailUtils::convert_html_to_text($bodyHtml);
        }

        // Should we inline css
        if (!$inlineCss && SparkPostHelper::config()->inline_styles) {
            $bodyHtml = EmailUtils::inline_styles($bodyHtml);
        }

        if ($message->getHeaders()->has('List-Unsubscribe')) {
            $headers['List-Unsubscribe'] = $message->getHeaders()->get('List-Unsubscribe')->getValue();
        }

        $defaultParams = SparkPostHelper::config()->default_params;
        if ($inlineCss !== null) {
            $defaultParams['inline_css'] = $inlineCss;
        }

        // Build base transmission
        $sparkPostMessage = array(
            'recipients' => $recipients,
            'reply_to' => $reply_to,
            'content' => array(
                'from' => array(
                    'name' => $fromFirstName,
                    'email' => $fromFirstEmail,
                ),
                'subject' => $message->getSubject(),
                'html' => $bodyHtml,
                'text' => $bodyText,
            ),
        );

        // Add default params
        $sparkPostMessage = array_merge($defaultParams, $sparkPostMessage);

        // Add remaining elements
        if (!empty($cc)) {
            $sparkPostMessage['cc'] = $cc;
        }
        if (!empty($bcc)) {
            $sparkPostMessage['bcc'] = $bcc;
        }
        if (!empty($headers)) {
            $sparkPostMessage['headers'] = $headers;
        }
        if (count($attachments) > 0) {
            $sparkPostMessage['attachments'] = $attachments;
        }

        return $sparkPostMessage;
    }

    /**
     * @return null|array
     */
    public function getResultApi()
    {
        return $this->resultApi;
    }
}
