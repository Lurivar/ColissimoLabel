<?php

namespace ColissimoLabel\Controller\Admin;

use ColissimoLabel\ColissimoLabel;
use ColissimoLabel\Event\ColissimoLabelEvents;
use ColissimoLabel\Event\LabelRequestEvent;
use ColissimoLabel\Model\ColissimoLabel as ColissimoLabelModel;
use ColissimoLabel\Model\ColissimoLabelQuery;
use ColissimoLabel\Service\SOAPService;
use ColissimoLabel\Request\Helper\LabelRequestAPIConfiguration;
use ColissimoLabel\Request\LabelRequest;
use ColissimoWs\Model\ColissimowsLabelQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Exception\PropelException;
use SoColissimo\Model\OrderAddressSocolissimoQuery;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Thelia\Controller\Admin\AdminController;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\JsonResponse;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ModuleQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatusQuery;
use Thelia\Tools\URL;

/**
 * @author Gilles Bourgeat >gilles.bourgeat@gmail.com>
 */
class OrderController extends AdminController
{
    public function generateLabelAction(Request $request, $orderId)
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::UPDATE)) {
            return new JsonResponse([
                'error' => $this->getTranslator()->trans("Sorry, you're not allowed to perform this action")
            ], 403);
        }

        /** Make sure label and bordereau exists, creates them otherwise */
        ColissimoLabel::checkLabelFolder();

        $order = OrderQuery::create()->filterById((int)$orderId, Criteria::EQUAL)->findOne();

        $APIConfiguration = new LabelRequestAPIConfiguration();
        $APIConfiguration->setContractNumber(ColissimoLabel::getConfigValue(ColissimoLabel::CONFIG_KEY_CONTRACT_NUMBER));
        $APIConfiguration->setPassword(ColissimoLabel::getConfigValue(ColissimoLabel::CONFIG_KEY_PASSWORD));

        if ('SoColissimo' === $order->getModuleRelatedByDeliveryModuleId()->getCode()) {
            if (null !== $addressSocolissimo = OrderAddressSocolissimoQuery::create()
                    ->findOneById($order->getDeliveryOrderAddressId())) {
                if ($addressSocolissimo) {
                    $colissimoRequest = new LabelRequest(
                        $order,
                        $addressSocolissimo->getCode() == '0' ? null : $addressSocolissimo->getCode(),
                        $addressSocolissimo->getType()
                    );

                    $colissimoRequest->getLetter()->getService()->setCommercialName(
                        $colissimoRequest->getLetter()->getSender()->getAddress()->getCompanyName()
                    );
                }
            }
        }

        /** TODO : Handle from other pages */
        $signedDelivery = null !== $request->get('signedDelivery');

        if (!isset($colissimoRequest)) {
            $colissimoRequest = new LabelRequest($order, null, null, $signedDelivery);
        }

        if (null !== $weight = $request->get('weight')) {
            $colissimoRequest->getLetter()->getParcel()->setWeight($weight);
        }

        if (null !== $signedDelivery) {
            $colissimoRequest->getLetter()->getParcel()->setSignedDelivery($signedDelivery);
        }

        $service = new SOAPService();

        $this->getDispatcher()->dispatch(
            ColissimoLabelEvents::LABEL_REQUEST,
            new LabelRequestEvent($colissimoRequest)
        );

        $response = $service->callAPI($APIConfiguration, $colissimoRequest);

        /** TODO : Redo from here */

        if ($response->isValid()) {
            $fileSystem = new Filesystem();

            $fileSystem->dumpFile(
                ColissimoLabel::getLabelPath($response->getParcelNumber(), ColissimoLabel::getExtensionFile()),
                $response->getFile()
            );

            if ($response->hasFileCN23()) {
                $fileSystem->dumpFile(
                    ColissimoLabel::getLabelCN23Path($response->getParcelNumber(), ColissimoLabel::getExtensionFile()),
                    $response->getFileCN23()
                );
            }

            $colissimoLabelModel = (new ColissimoLabelModel())
                ->setOrderId($order->getId())
                ->setWeight($colissimoRequest->getLetter()->getParcel()->getWeight())
                ->setNumber($response->getParcelNumber())
                ->setSigned($signedDelivery)
            ;

            $colissimoLabelModel->save();

            $order->setDeliveryRef($response->getParcelNumber());

            $order->save();

            if ((int) ColissimoLabel::getConfigValue(ColissimoLabel::CONFIG_KEY_AUTO_SENT_STATUS)) {
                $sentStatusId = (int) ColissimoLabel::getConfigValue(ColissimoLabel::CONFIG_KEY_SENT_STATUS_ID);

                if ((int) $order->getOrderStatus()->getId() !== (int) $sentStatusId) {
                    $order->setOrderStatus(
                        OrderStatusQuery::create()->findOneById((int) $sentStatusId)
                    );
                    $this->getDispatcher()->dispatch(
                        TheliaEvents::ORDER_UPDATE_STATUS,
                        (new OrderEvent($order))->setStatus((int) $sentStatusId)
                    );
                }
            }

            return new JsonResponse([
                'id' => $colissimoLabelModel->getId(),
                'url' => URL::getInstance()->absoluteUrl('/admin/module/colissimolabel/label/' . $response->getParcelNumber()),
                'number' => $response->getParcelNumber(),
                'order' => [
                    'id' => $order->getId(),
                    'status' => [
                        'id' => $order->getOrderStatus()->getId()
                    ]
                ]
            ]);
        } else {
            return new JsonResponse([
                'error' => $response->getError()
            ]);
        }
    }

    public function getOrderLabelsAction($orderId)
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::UPDATE)) {
            return new Response($this->getTranslator()->trans("Sorry, you're not allowed to perform this action"), 403);
        }

        return $this->render('colissimo-label/label-list', ['order_id' => $orderId]);
    }

    /**
     * Delete the label and invoice files on the server thanks to the label name
     *
     * @param $fileName
     */
    protected function deleteLabelFile($fileName) {
        $finder = new Finder();
        $fileSystem = new Filesystem();

        $finder->files()->name($fileName . '*')->in(ColissimoLabel::LABEL_FOLDER);
        foreach ($finder as $file) {
            $fileSystem->remove(ColissimoLabel::LABEL_FOLDER . DS . $file->getFilename());
        }
    }

    /**
     * Delete the label
     *
     * Compatibility with ColissimoLabel < 1.0.0
     * Compatibility with ColissimoWs < 2.0.0
     *
     * @param Request $request
     * @param $trackingNumber
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws PropelException
     */
    public function deleteLabelAction(Request $request, $number) {
        $label = ColissimoLabelQuery::create()->findOneByTrackingNumber($number);
        /** We check if the label is from this module -- Compatibility with ColissimoWs */
        if ($label) {
            /** We check if the label is from this version of the module -- Compatibility with ColissimoLabel < 1.0.0 */
            if ('' !== $orderRef = $label->getOrderRef()) {
                $this->deleteLabelFile($orderRef);
                $label->delete();

                //TODO : Reload the page on the same position
                return $this->generateRedirect(URL::getInstance()->absoluteUrl('admin/module/colissimolabel/labels'));
            }
        }

        /**
         * If we're here, it means the label was not from this module or module version, so we get it by other means
         * for compatibility reasons.
         */

        /** Trying to get it from ColissimoWs */
        if ($orderId = $request->get('order')) {
            /** Checking is ColissimoWs is installed */
            if (ModuleQuery::create()->findOneByCode(ColissimoLabel::AUTHORIZED_MODULES[0])) {
                /** Checking if the label entry exists in the deprecated ColissimoWsLabel table */
                if ($colissimoWslabel = ColissimowsLabelQuery::create()->findOneByOrderId($orderId)) {
                    $orderRef = $colissimoWslabel->getOrderRef();
                    $this->deleteLabelFile($orderRef);

                    $colissimoWslabel->delete();

                    //TODO : Reload the page on the same position
                    return $this->generateRedirect(URL::getInstance()->absoluteUrl('admin/module/colissimolabel/labels'));
                }
            }
        }

        /**
         * If we're here, it means the label is coming from a version of ColissimoLabel < 1.0.0
         * So we need to delete it with its tracking number instead of order ref, since it was named like that back then
         */
        $this->deleteLabelFile($number);
        $label->delete();

        //TODO : Reload the page on the same position
        return $this->generateRedirect(URL::getInstance()->absoluteUrl('admin/module/colissimolabel/labels'));
    }

    /**
     * Get the order label from the server
     *
     * @param Request $request
     * @param $number
     * @return mixed|BinaryFileResponse
     */
    public function getLabelAction(Request $request, $number)
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::UPDATE)) {
            return $response;
        }

        //Todo
        ///** Compatibility with ColissimoWs /!\ Do not use strict comparison */
        //if (ModuleQuery::create()->findOneByCode('ColissimoWs')->getActivate() == true) {
        //    if ($cws = ColissimowsLabelQuery::create()->findOneByTrackingNumber($number)) {
        //        /** Cheating by changing the parcel number by the order ref which is the filename in ColissimoWs */
        //        $number = $cws->getOrderRef();
        //    }
        //}
        $response = new BinaryFileResponse(
            ColissimoLabel::getLabelPath($number, ColissimoLabel::getExtensionFile())
        );

        $ext = strtolower(substr(ColissimoLabel::getConfigValue(ColissimoLabel::CONFIG_KEY_DEFAULT_LABEL_FORMAT), 3));

        if ($request->get('download')) {
            $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $number . '.' . ColissimoLabel::getExtensionFile()
            );
        }

        return $response;
    }
}
