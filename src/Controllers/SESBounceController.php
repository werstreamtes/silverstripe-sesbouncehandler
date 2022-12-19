<?php

namespace WSE\SESBounceHandler\Controllers;

use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Member;

class SESBounceController extends Controller
{

    /**
     * @return LoggerInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function logger(): LoggerInterface
    {
        return Injector::inst()->get(LoggerInterface::class);
    }

    public function handleRequest(HTTPRequest $request)
    {
        // request must be post:
        if (!$request->isPOST()) {
            return $this->httpError(405, 'Method Not Allowed');
        }

        $message = Message::fromRawPostData();
        $this->logger()->info("Message received", $message->toArray());

        // Validate the message
        $validator = new MessageValidator();
        if (!$validator->isValid($message)) {
            $this->logger()->warning("Message is not valid", $message->toArray());
            return $this->httpError(400, "Message could not be validated");
        }

        // get type of message:
        $messageType = $message['Type'];
        $messageHandler = 'handle' . $messageType;
        if (!$this->hasMethod($messageHandler)) {
            $this->logger()->warning("No handler found for message type", $message->toArray());
            return $this->httpError(404, "No handler found for message type");
        }

        return $this->$messageHandler($message);
    }

    private function handleSubscriptionConfirmation(Message $message)
    {
        // call the SubscribeURL:
        file_get_contents($message['SubscribeURL']);
        return "ok";
    }

    private function handleNotification(Message $message)
    {
        // unpack the inner message:
        $sesMessage = json_decode($message['Message']);

        switch ($sesMessage->notificationType) {
            case 'Bounce' or 'Complaint':
                // gmx and maybe others trigger "bounceType":"Transient","bounceSubType":"General" for whatever reasons.
                // and AWS states that Autoresponder can trigger it. So, we ignore it for now:
                if ($sesMessage->bounce->bounceType == 'Transient' && $sesMessage->bounce->bounceSubType == 'General') {
                    $this->logger()->debug("Ignoring Bounce/Transient/General.", $message->toArray());
                    return 'ok';
                }

                return $this->handleBounce($sesMessage->mail);
                break;
            case 'Delivery':
                // no one cares, currently
                return "ok";
                break;
            default:
                // not good, how did we end up here?
                $this->logger()->warning("Unknown notificationType found in message", $message->toArray());
                return $this->httpError(404, "Unknown notificationType");
        }

        return $this->httpError(404, "Unknown");
    }

    private function handleBounce($mail)
    {
        foreach ($mail->destination as $emailAddress) {
            // try to find user in SilverStripe:
            if ($member = Member::get()->filter("EMail", $emailAddress)->first()) {
                // if the member does not have a validation value give him one:
                if (empty($member->EMailVerification)) {
                    $member->setNewEMailVerificationValue();
                    $member->write();
                    $this->logger()->debug(
                        "Set new EMailVerification Value for Member",
                        ['MemberID' => $member->ID, "Address" => $emailAddress]
                    );
                }
            }
        }
        return "ok";
    }
}
