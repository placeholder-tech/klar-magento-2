<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Model\Data;

use PlaceholderTech\Klar\Api\Data\CustomerInterface;
use Magento\Framework\DataObject;

class Customer extends DataObject implements CustomerInterface
{
    /**
     * Getter for Id.
     *
     * @return string|null
     */
    public function getId(): ?string
    {
        return $this->getData(self::ID);
    }

    /**
     * Setter for Id.
     *
     * @param string|null $id
     *
     * @return void
     */
    public function setId(?string $id): void
    {
        $this->setData(self::ID, $id);
    }

    /**
     * Getter for Email.
     *
     * @return string|null
     */
    public function getEmail(): ?string
    {
        return $this->getData(self::EMAIL);
    }

    /**
     * Setter for Email Hash.
     *
     * @param string|null $email
     *
     * @return void
     */
    public function setEmailHash(?string $emailHash): void
    {
        $this->setData(self::EMAIL_HASH, $emailHash);
    }

    /**
     * Getter for Email Hash.
     *
     * @return string|null
     */
    public function getEmailHash(): ?string
    {
        return $this->getData(self::EMAIL_HASH);
    }

    /**
     * Setter for Email.
     *
     * @param string|null $email
     *
     * @return void
     */
    public function setEmail(?string $email): void
    {
        $this->setData(self::EMAIL, $email);
    }


    /**
     * Getter for IsNewsletterSubscriberAtTimeOfCheckout.
     *
     * @return bool|null
     */
    public function getIsNewsletterSubscriberAtTimeOfCheckout(): ?bool
    {
        return $this->getData(self::IS_NEWSLETTER_SUBSCRIBER_AT_TIME_OF_CHECKOUT) === null ? null
            : (bool)$this->getData(self::IS_NEWSLETTER_SUBSCRIBER_AT_TIME_OF_CHECKOUT);
    }

    /**
     * Setter for IsNewsletterSubscriberAtTimeOfCheckout.
     *
     * @param bool|null $isNewsletterSubscriberAtTimeOfCheckout
     *
     * @return void
     */
    public function setIsNewsletterSubscriberAtTimeOfCheckout(?bool $isNewsletterSubscriberAtTimeOfCheckout): void
    {
        $this->setData(self::IS_NEWSLETTER_SUBSCRIBER_AT_TIME_OF_CHECKOUT, $isNewsletterSubscriberAtTimeOfCheckout);
    }

    /**
     * Getter for Tags.
     *
     * @return array|null
     */
    public function getTags(): ?array
    {
        return $this->getData(self::TAGS);
    }

    /**
     * Setter for Tags.
     *
     * @param array|null $tags
     *
     * @return void
     */
    public function setTags(?array $tags): void
    {
        // Retrieve existing tags, ensure both existing and new tags are arrays of strings
        $existingTags = array_map('strval', (array) $this->getData(self::TAGS));
        $newTags = array_map('strval', (array) $tags);
    
        // Merge and set the tags
        $this->setData(self::TAGS, array_merge($existingTags, $newTags));
    }
}
