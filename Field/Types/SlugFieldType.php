<?php

namespace Karls\SlugBundle\Field\Types;

use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Routing\Router;
use UniteCMS\CoreBundle\Exception\ContentAccessDeniedException;
use UniteCMS\CoreBundle\Exception\ContentTypeAccessDeniedException;
use UniteCMS\CoreBundle\Exception\DomainAccessDeniedException;
use UniteCMS\CoreBundle\Exception\InvalidFieldConfigurationException;
use UniteCMS\CoreBundle\Exception\MissingContentTypeException;
use UniteCMS\CoreBundle\Exception\MissingDomainException;
use UniteCMS\CoreBundle\Exception\MissingOrganizationException;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\TwigEngine;
use Symfony\Component\Form\Exception\InvalidArgumentException;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use UniteCMS\CoreBundle\Entity\Content;
use UniteCMS\CoreBundle\Entity\FieldableContent;
use UniteCMS\CoreBundle\Entity\FieldableField;
use UniteCMS\CoreBundle\Form\ReferenceType;
use UniteCMS\CoreBundle\SchemaType\IdentifierNormalizer;
use UniteCMS\CoreBundle\Security\Voter\DomainVoter;
use UniteCMS\CoreBundle\View\ViewTypeInterface;
use UniteCMS\CoreBundle\View\ViewTypeManager;
use UniteCMS\CoreBundle\Entity\View;
use UniteCMS\CoreBundle\Entity\ContentType;
use UniteCMS\CoreBundle\Entity\Domain;
use UniteCMS\CoreBundle\Field\FieldType;
use UniteCMS\CoreBundle\Security\Voter\ContentVoter;
use UniteCMS\CoreBundle\Service\UniteCMSManager;
use UniteCMS\CoreBundle\SchemaType\SchemaTypeManager;
use UniteCMS\CoreBundle\Field\FieldableFieldSettings;
use Karls\SlugBundle\Form\SlugType;
use Symfony\Component\DependencyInjection\Container;

class SlugFieldType extends FieldType
{
    const TYPE = "slug";
    const FORM_TYPE = HiddenType::class;
    const SETTINGS = ['source'];

    private $settings;

    private $entityManager;

    /**
     * @var Container
     */
    private $container;

    /**
     * SlugFieldType constructor.
     * @param EntityManagerInterface $entityManager
     * @param Container $container
     */
    public function __construct(EntityManagerInterface $entityManager, Container $container)
    {
        $this->entityManager = $entityManager;
        $this->container = $container;
    }

    function getFormOptions(FieldableField $field): array
    {
        $this->settings = (array) $field->getSettings();

        return parent::getFormOptions($field);
    }

    /**
     * {@inheritdoc}
     */
    function getGraphQLInputType(FieldableField $field, SchemaTypeManager $schemaTypeManager, $nestingLevel = 0) {
        return $schemaTypeManager->getSchemaType('SlugFieldInput');
    }

    /**
     * {@inheritdoc}
     */
    function resolveGraphQLData(FieldableField $field, $value)
    {
        // return NULL on empty value
        if (empty($value))
        {
            return NULL;
        }
        return (string) $value;
    }

    /**
     * {@inheritdoc}
     */
    function validateSettings(FieldableFieldSettings $settings, ExecutionContextInterface $context)
    {
        // Validate allowed and required settings.
        parent::validateSettings($settings, $context);

        // Only continue, if there are no violations yet.
        if ($context->getViolations()->count() > 0) {
            return;
        }

        // initial place must be a string
        if(!is_string($settings->source)) {
            $context->buildViolation('slug_source_not_exists')->atPath('source')->addViolation();
            return;
        }

        $sourceExistsInContentType = FALSE;
        foreach ($context->getObject()->getContentType()->getFields() as $field){
            if($field->getIdentifier() == $settings->source) {
                $sourceExistsInContentType = TRUE;
            }
        }
        if(!$sourceExistsInContentType) {
            $context->buildViolation('slug_source_not_exists')->atPath('source')->addViolation();
            return;
        }

    }

    /**
     * {@inheritdoc}
     */
    function validateData(FieldableField $field, $data, ExecutionContextInterface $context) {
        if (empty($data) || !count($context->getValue()) || !$this->settings['source']) {
            return;
        }

        $this->checkIfSlugStillExists($this->slugify($context->getValue()[$this->settings['source']]), $context, $field->getContentType());
    }

    /**
     * @param string $slug
     * @param ExecutionContextInterface $context
     * @param null $contentType
     */
    private function checkIfSlugStillExists($slug, ExecutionContextInterface $context, $contentType = null) {
        $queryBuilder = $this->entityManager->getRepository('UniteCMSCoreBundle:Content')->createQueryBuilder("content");
        $query = $queryBuilder
            ->select("c")
            ->from(\UniteCMS\CoreBundle\Entity\Content::class, "c")
            ->where("JSON_EXTRACT(c.data, :jsonPath) = :value ")
            ->andWhere("c.contentType = :contentType ")
            ->andWhere("c.locale = 'de'")
            ->setParameter('jsonPath', '$.slug')
            ->setParameter('value', $slug)
            ->setParameter('contentType', $contentType->getId());
        if($currentObject = $context->getObject()) {
            $query
                ->andWhere("c.id != :currentContentId")
                ->setParameter('currentContentId', $currentObject->getId());
        }
        $query->setMaxResults(1);

        $content = $query->getQuery()->getResult();

        if(count($content)) {

//            throw new InvalidArgumentException(
//                $this->container->get('translator')->trans('slug_still_exists', ['%source%'=> $this->settings['source'], '%value%'=> $slug], 'validators')
//            );

            $context->buildViolation('slug_still_exists', ['%source%'=> $this->settings['source'], '%value%'=> $slug])
                ->atPath('['.$this->settings['source'].']')
                ->addViolation();
        }
    }

    /**
     * Slugify Text
     *
     * @param $text
     * @return null|string
     */
    public static function slugify($text)
    {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        $text = preg_replace('~-+~', '-', $text);
        $text = strtolower($text);
        if (empty($text)) {
            return uniqid();
        }
        return $text;
    }

    /**
     * {@inheritdoc}
     */
    public function onCreate(FieldableField $field, FieldableContent $content, EntityRepository $repository, &$data) {
        $data["slug"] = $this->slugify($data[$this->settings['source']]);
        $content->setData($data);
        $this->container->get('validator')->validate($content);
    }

    /**
     * {@inheritdoc}
     */
    public function onUpdate(FieldableField $field, FieldableContent $content, EntityRepository $repository, $old_data, &$data) {
        $data["slug"] = $this->slugify($data[$this->settings['source']]);
        $content->setData($data);
        $this->container->get('validator')->validate($content);
    }
}
