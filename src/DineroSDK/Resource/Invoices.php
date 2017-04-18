<?php

namespace DineroSDK\Resource;

use DineroSDK\Entity\Contact;
use DineroSDK\Entity\Invoice;
use DineroSDK\Exception\DineroException;
use DineroSDK\Exception\DineroMissingParameterException;
use Zend\Hydrator\ClassMethods;
use Zend\Hydrator\ObjectProperty;

class Invoices extends AbstractResource
{
    /**
     * If $contact is supplied, the SDK will first search for the contact in Dinero
     * and if it doesn't exist it will create it
     *
     * @param Invoice $invoice
     * @param Contact|null $contact
     * @param bool $book
     * @throws DineroMissingParameterException
     * @throws DineroException
     * @return Invoice
     */
    public function create(Invoice $invoice, Contact $contact = null, $book = false)
    {
        $endpoint = sprintf('/%s/invoices', $this->dinero->getOrganizationId());

        if (!$invoice->getContactGuid() && !$contact) {
            throw new DineroMissingParameterException('Invoice requires a \'Contact\'. You need to specify either a Contact Guid or pass a Contact Entity');
        }

        if ($contact) {
            if ($contact->getContactGuid()) {
                $invoice->setContactGuid($contact->getContactGuid());
            } else {
                $queryFilter = [];

                if ($contact->getExternalReference()) {
                    $queryFilter['ExternalReference'] = $contact->getExternalReference();
                }
                if ($contact->getName()) {
                    $queryFilter['Name'] = $contact->getName();
                }
                if ($contact->getVatNumber()) {
                    $queryFilter['VatNumber'] = $contact->getVatNumber();
                }
                if ($contact->getEanNumber()) {
                    $queryFilter['EanNumber'] = $contact->getEanNumber();
                }
                if ($contact->getIsPerson()) {
                    $queryFilter['IsPerson'] = $contact->getIsPerson();
                }
                if ($contact->getEmail()) {
                    $queryFilter['Email'] = $contact->getEmail();
                }

                $dineroLookup = $this->dinero->contacts()->find($queryFilter);

                if (!count($dineroLookup)) {
                    $contact = $this->dinero->contacts()->create($contact);
                } else {
                    if (count($dineroLookup) > 1) {
                        throw new DineroException('Found multiple Contacts while trying to create invoice');
                    }

                    $contact = $dineroLookup[0];
                }

                $invoice->setContactGuid($contact->getContactGuid());
            }
        }

        $hydrator = new ObjectProperty();
        $invoiceArray = $hydrator->extract($invoice);

        $invoiceLines = [];
        foreach ($invoice->getProductLines() as $productLine) {
            if (!$productLine->getUnit()) {
                throw new DineroMissingParameterException('InvoiceLine requires an \'Unit\'');
            }

            if (!$productLine->getQuantity()) {
                throw new DineroMissingParameterException('InvoiceLine requires a \'Quantity\'');
            }

            if (!$productLine->getDescription()) {
                throw new DineroMissingParameterException('InvoiceLine requires a \'Description\'');
            }

            if (!$productLine->getBaseAmountValue()) {
                throw new DineroMissingParameterException('InvoiceLine requires a \'BaseAmountValue\'');
            }

            if (!$productLine->getAccountNumber()) {
                throw new DineroMissingParameterException('InvoiceLine requires an \'AccountNumber\'');
            }
            $invoiceLines[] = $hydrator->extract($productLine);
        }

        $invoiceArray['ProductLines'] = $invoiceLines;

        $result = $this->dinero->send($endpoint, 'post', json_encode($invoiceArray))->getBody();

        if ($book) {
            $bookEndpoint = sprintf('%s/%s/book', $endpoint, $result['Guid']);
            $this->dinero->send($bookEndpoint, 'post', json_encode(['Timestamp' => $result['TimeStamp']]));
        }

        return $invoice->withGuid($result['Guid']);
    }

    /**
     * Send invoice email
     * 
     * @param string $invoiceGuid
     * @param array $settings
     */
    public function sendEmail(string $invoiceGuid, array $settings = [])
    {
        $endpoint = sprintf('/%s/invoices/%s/email', $this->dinero->getOrganizationId(), $invoiceGuid);
        $settings = array_replace($this->dinero->getEmailSettings(), $settings);

        return $this->send($endpoint, 'post', json_encode($settings));
    }
}