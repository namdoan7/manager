<?php

/*
 * This file is part of the puli/repository-manager package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\RepositoryManager\Repository\Mapping;

use Puli\RepositoryManager\Api\Package\RootPackageFile;
use Puli\RepositoryManager\Api\Repository\ResourceMapping;
use Puli\RepositoryManager\Assert\Assert;
use Puli\RepositoryManager\Transaction\AtomicOperation;

/**
 * Removes a resource mapping from the root package file.
 *
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class RemoveMappingFromPackageFile implements AtomicOperation
{
    /**
     * @var string
     */
    private $repositoryPath;

    /**
     * @var RootPackageFile
     */
    private $rootPackageFile;

    /**
     * @var ResourceMapping
     */
    private $previousMapping;

    public function __construct($repositoryPath, RootPackageFile $rootPackageFile)
    {
        $this->repositoryPath = $repositoryPath;
        $this->rootPackageFile = $rootPackageFile;
    }

    /**
     * {@inheritdoc}
     */
    public function execute()
    {
        if ($this->rootPackageFile->hasResourceMapping($this->repositoryPath)) {
            $this->previousMapping = $this->rootPackageFile->getResourceMapping($this->repositoryPath);
            $this->rootPackageFile->removeResourceMapping($this->repositoryPath);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rollback()
    {
        if ($this->previousMapping) {
            $this->rootPackageFile->addResourceMapping($this->previousMapping);
        }
    }
}