<?php

namespace ColissimoLabel\Controller\Admin;

use ColissimoLabel\ColissimoLabel;
use ColissimoLabel\Model\ColissimoLabel as ColissimoLabelModel;
use ColissimoLabel\Model\ColissimoLabelQuery;
use ColissimoLabel\Request\Helper\BordereauRequestAPIConfiguration;
use ColissimoLabel\Service\SOAPService;
use ColissimoWs\Model\ColissimowsLabel;
use ColissimoWs\Model\ColissimowsLabelQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Thelia\Controller\Admin\AdminController;
use Thelia\Model\ModuleQuery;

class BordereauController extends AdminController
{
    public function listBordereauAction($error = null)
    {
        ColissimoLabel::checkLabelFolder();
        $lastBordereauDate = ColissimoLabel::getConfigValue(ColissimoLabel::CONFIG_KEY_LAST_BORDEREAU_DATE);

        $finder = new Finder();
        $finder->files()->in(ColissimoLabel::BORDEREAU_FOLDER);

        $bordereaux = [];
        foreach ($finder as $file) {
            $bordereaux[] = [
                "name" => $file->getRelativePathname(),
                "path" => $file->getRealPath(),
            ];
        }

        sort($bordereaux);
        $bordereaux = array_reverse($bordereaux);
        return $this->render('colissimo-label/bordereau-list', compact("lastBordereauDate", "bordereaux", "error"));
    }

    public function listLabelsAction()
    {
        ColissimoLabel::checkLabelFolder();

        return $this->render('colissimo-label/labels');
    }

    public function generateBordereauAction()
    {
        ColissimoLabel::checkLabelFolder();

        $lastBordereauDate = ColissimoLabel::getConfigValue(ColissimoLabel::CONFIG_KEY_LAST_BORDEREAU_DATE);

        $labels = ColissimoLabelQuery::create()
            ->filterByCreatedAt($lastBordereauDate, Criteria::GREATER_THAN)
            ->find();

        $parcelNumbers = [];

        /** @var ColissimoLabelModel $label */
        foreach ($labels as $label) {
            $parcelNumbers[] = $label->getTrackingNumber();
        }

        /** Compatibility with ColissimoWS < 2.0.0 */
        if (ModuleQuery::create()->findOneByCode('ColissimoWs')) {
            $labelsWs = ColissimowsLabelQuery::create()
                ->filterByCreatedAt($lastBordereauDate, Criteria::GREATER_THAN)
                ->find();

            /** @var ColissimowsLabel $label */
            foreach ($labelsWs as $labelWs) {
                $parcelNumbers[] = $labelWs->getTrackingNumber();
            }
        }

        $service = new SOAPService();
        $APIConfiguration = new BordereauRequestAPIConfiguration();
        $APIConfiguration->setContractNumber(ColissimoLabel::getConfigValue(ColissimoLabel::CONFIG_KEY_CONTRACT_NUMBER));
        $APIConfiguration->setPassword(ColissimoLabel::getConfigValue(ColissimoLabel::CONFIG_KEY_PASSWORD));

        $parseResponse = $service->callGenerateBordereauByParcelsNumbersAPI($APIConfiguration, $parcelNumbers);
        $resultAttachment = $parseResponse->attachments;
        if (!isset($resultAttachment[0])) {
            if (!isset($parseResponse->soapResponse['data'])) {
                return $this->listBordereauAction('No label found');
            }
            return $this->listBordereauAction('Error : ' . $this->getError($parseResponse->soapResponse['data']));
        }
        $bordereauContent = $resultAttachment[0];
        $fileContent = $bordereauContent['data'];

        if ('' == $fileContent) {
            throw new \Exception('File is empty');
        }

        $filePath = ColissimoLabel::getBordereauPath('bordereau_' .(new \DateTime())->format('Y-m-d_H-i-s'));

        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $filePath,
            $fileContent
        );

        ColissimoLabel::setConfigValue(ColissimoLabel::CONFIG_KEY_LAST_BORDEREAU_DATE, (new \DateTime())->format('Y-m-d H:i:s'));

        return $this->listBordereauAction();
    }

    /**
     * Return the error message contained in the SOAP response from Colissimo
     *
     * @param $data
     * @return array
     */
    protected function getError($data) {
        $errorMessage = explode("<messageContent>", $data);
        $errorMessage = explode("</messageContent>", $errorMessage[1]);

        return $errorMessage[0];
    }

    /**
     * Retrieve a bordereau on the server given its filename passed in the request, and return it as a binary response
     *
     * @return BinaryFileResponse
     */
    public function downloadBordereauAction()
    {
        $filePath = $this->getRequest()->get('filePath');

        return new BinaryFileResponse($filePath);
    }
}