<?php

namespace Mautic\SmsBundle\Controller\Api;

use Mautic\ApiBundle\Controller\CommonApiController;
use Mautic\LeadBundle\Controller\LeadAccessTrait;
use Mautic\SmsBundle\Entity\Sms;
use Mautic\SmsBundle\Model\SmsModel;
use Mautic\SmsBundle\Sms\TransportChain;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;

/**
 * @extends CommonApiController<Sms>
 */
class SmsApiController extends CommonApiController
{
    use LeadAccessTrait;

    /**
     * @var SmsModel|null
     */
    protected $model = null;

    public function initialize(ControllerEvent $event)
    {
        $smsModel = $this->getModel('sms');
        \assert($smsModel instanceof SmsModel);

        $this->model           = $smsModel;
        $this->entityClass     = Sms::class;
        $this->entityNameOne   = 'sms';
        $this->entityNameMulti = 'smses';

        parent::initialize($event);
    }

    /**
     * @param $id
     * @param $contactId
     *
     * @return JsonResponse|Response
     */
    public function sendAction(TransportChain $transportChain, LoggerInterface $mauticLogger, $id, $contactId)
    {
        if (!$transportChain->getEnabledTransports()) {
            return new JsonResponse(json_encode(['error' => ['message' => 'SMS transport is disabled.', 'code' => Response::HTTP_EXPECTATION_FAILED]]));
        }

        $message = $this->model->getEntity((int) $id);

        if (is_null($message)) {
            return $this->notFound();
        }

        $contact = $this->checkLeadAccess($contactId, 'edit');

        if ($contact instanceof Response) {
            return $this->accessDenied();
        }

        $mauticLogger->debug("Sending SMS #{$id} to contact #{$contactId}", ['originator' => 'api']);

        try {
            $response = $this->model->sendSms($message, $contact, ['channel' => 'api'])[$contact->getId()];
        } catch (\Exception $e) {
            $mauticLogger->error($e->getMessage(), ['error' => (array) $e]);

            return new Response('Interval server error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $success = !empty($response['sent']);

        if (!$success) {
            $mauticLogger->error('Failed to send SMS.', ['error' => $response['status']]);
        }

        $view = $this->view(
            [
                'success' => $success,
                'status'  => $this->translator->trans($response['status']),
                'result'  => $response,
                'errors'  => $success ? [] : [['message' => $response['status']]],
            ],
            Response::HTTP_OK  //  200 - is legacy, we cannot change it yet
        );

        return $this->handleView($view);
    }
}
