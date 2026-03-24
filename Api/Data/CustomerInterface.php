<?php
declare(strict_types=1);

namespace PlaceholderTech\Klar\Api\Data;

interface CustomerInterface
{
    /**
     * String constants for property names
     */
    public const ID = 'id';
    public const EMAIL = 'email';
    public const IS_NEWSLETTER_SUBSCRIBER_AT_TIME_OF_CHECKOUT = 'is_newsletter_subscriber_at_time_of_checkout';
    public const TAGS = 'tags';
    public const EMAIL_HASH = 'emailHash';
    /**
     * Getter for Id.
     *
     * @return string|null
     */
    public function getId(): ?string;

    /**
     * Setter for Id.
     *
     * @param string|null $id
     *
     * @return void
     */
    public function setId(?string $id): void;

    /**
     * Getter for Email.
     *
     * @return string|null
     */
    public function getEmail(): ?string;

    /**
     * Setter for Email.
     *
     * @param string|null $email
     *
     * @return void
     */
    public function setEmail(?string $email): void;

    /**
     * Getter for Email Hash.
     *
     * @return string|null
     */
    public function getEmailHash(): ?string;

    /**
     * Setter for Email Hash.
     *
     * @param string|null $emailHash
     *
     * @return void
     */
    public function setEmailHash(?string $emailHash): void;

    /**
     * Getter for IsNewsletterSubscriberAtTimeOfCheckout.
     *
     * @return bool|null
     */
    public function getIsNewsletterSubscriberAtTimeOfCheckout(): ?bool;

    /**
     * Setter for IsNewsletterSubscriberAtTimeOfCheckout.
     *
     * @param bool|null $isNewsletterSubscriberAtTimeOfCheckout
     *
     * @return void
     */
    public function setIsNewsletterSubscriberAtTimeOfCheckout(?bool $isNewsletterSubscriberAtTimeOfCheckout): void;

    /**
     * Getter for Tags.
     *
     * @return array|null
     */
    public function getTags(): ?array;

    /**
     * Setter for Tags.
     *
     * @param array|null $tags
     *
     * @return void
     */
    public function setTags(?array $tags): void;
}
