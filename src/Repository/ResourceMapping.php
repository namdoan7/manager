<?php

/*
 * This file is part of the puli/repository-manager package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\RepositoryManager\Repository;

use Exception;
use InvalidArgumentException;
use LogicException;
use Puli\RepositoryManager\Assert\Assert;
use Puli\RepositoryManager\FileNotFoundException;
use Puli\RepositoryManager\Package\NoSuchPackageException;
use Puli\RepositoryManager\Package\Package;
use Puli\RepositoryManager\Package\PackageCollection;
use Webmozart\PathUtil\Path;

/**
 * Maps a repository path to one or more filesystem paths.
 *
 * The filesystem paths are passed in the form of *path references* that are
 * either paths relative to the package's root directory or paths relative
 * to another packages's root directory prefixed with `@vendor/package:`,
 * where "vendor/package" is the name of the referenced package.
 *
 * The path references are turned into absolute filesystem paths when
 * {@link load()} is called.
 *
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class ResourceMapping
{
    /**
     * @var string
     */
    private $repositoryPath;

    /**
     * @var string
     */
    private $pathReferences = array();

    /**
     * @var string
     */
    private $filesystemPaths = array();

    /**
     * @var Package
     */
    private $containingPackage;

    /**
     * @var int|null
     */
    private $state;

    /**
     * @var Exception[]
     */
    private $loadErrors = array();

    /**
     * @var RepositoryPathConflict[]
     */
    private $conflicts = array();

    /**
     * Creates a new resource mapping.
     *
     * @param string          $repositoryPath The repository path.
     * @param string|string[] $pathReferences The path references.
     *
     * @throws InvalidArgumentException If any of the arguments is invalid.
     */
    public function __construct($repositoryPath, $pathReferences)
    {
        Assert::path($repositoryPath);

        $pathReferences = (array) $pathReferences;

        Assert::notEmpty($pathReferences, 'At least one filesystem path must be passed.');
        Assert::allString($pathReferences, 'The filesystem paths must be strings. Got: %s');
        Assert::allNotEmpty($pathReferences, 'The filesystem paths must not be empty.');

        $this->repositoryPath = $repositoryPath;
        $this->pathReferences = $pathReferences;
    }

    /**
     * Loads the filesystem paths for the path references.
     *
     * @param Package           $containingPackage The package that contains the
     *                                             mapping.
     * @param PackageCollection $packages          A list of packages that can
     *                                             be referenced using
     *                                             `@vendor/package:` prefixes
     *                                             in the path references.
     * @param bool              $failIfNotFound    Whether to fail when a path
     *                                             or package is not found. By
     *                                             default, errors are stored
     *                                             in the mapping and can be
     *                                             accessed by calling
     *                                             {@link getLoadErrors()}.
     *
     * @throws MappingAlreadyLoadedException If the package has already been loaded.
     */
    public function load(Package $containingPackage, PackageCollection $packages, $failIfNotFound = false)
    {
        if ($this->isLoaded()) {
            throw new MappingAlreadyLoadedException('The mapping is already loaded.');
        }

        $absoluteFilesystemPaths = array();
        $loadErrors = array();

        foreach ($this->pathReferences as $relativePath) {
            $loadError = null;

            try {
                $absolutePath = $this->makeAbsolute($relativePath, $containingPackage, $packages);
                $this->assertFileExists($absolutePath, $relativePath, $containingPackage);

                $absoluteFilesystemPaths[] = $absolutePath;
            } catch (NoSuchPackageException $loadError) {
            } catch (FileNotFoundException $loadError) {
            }

            if (!$loadError) {
                continue;
            }

            if ($failIfNotFound) {
                throw $loadError;
            }

            $loadErrors[] = $loadError;
        }

        $this->filesystemPaths = $absoluteFilesystemPaths;
        $this->loadErrors = $loadErrors;
        $this->containingPackage = $containingPackage;

        $this->refreshState();
    }

    /**
     * Unloads the mapping.
     *
     * This method reverses the effects of {@link load()}. Additionally, all
     * associated conflicts are dereferenced.
     *
     * @throws MappingNotLoadedException If the mapping is not loaded.
     */
    public function unload()
    {
        if (!$this->isLoaded()) {
            throw new MappingNotLoadedException('The mapping is not loaded.');
        }

        $conflictsToRelease = $this->conflicts;

        $this->conflicts = array();

        foreach ($conflictsToRelease as $conflict) {
            $conflict->removeMapping($this);
        }

        $this->filesystemPaths = array();
        $this->loadErrors = array();
        $this->containingPackage = null;
        $this->state = null;
    }

    /**
     * Returns the repository path.
     *
     * @return string The repository path.
     */
    public function getRepositoryPath()
    {
        return $this->repositoryPath;
    }

    /**
     * Returns the path references.
     *
     * The path references refer to filesystem paths. A path reference is
     * either:
     *
     *  * a path relative to the root directory of the containing package;
     *  * a path relative to the root directory of another package, prefixed
     *    with `@vendor/package:`, where "vendor/package" is the name of the
     *    referenced package.
     *
     * @return string[] The path references.
     */
    public function getPathReferences()
    {
        return $this->pathReferences;
    }

    /**
     * Returns the referenced filesystem paths.
     *
     * If the mapping is not loaded with {@link load()}, an exception is
     * thrown.
     *
     * @return string[] The absolute filesystem paths.
     *
     * @throws MappingNotLoadedException If the mapping is not loaded.
     */
    public function getFilesystemPaths()
    {
        if (!$this->isLoaded()) {
            throw new MappingNotLoadedException('The mapping is not loaded.');
        }

        return $this->filesystemPaths;
    }

    /**
     * Returns the package that contains the mapping.
     *
     * The method {@link load()} needs to be called before accessing the
     * containing package, otherwise this method throws an exception.
     *
     * @return Package The containing package or `null` if the mapping has not
     *                 been loaded.
     *
     * @throws MappingNotLoadedException If the mapping is not loaded.
     */
    public function getContainingPackage()
    {
        if (!$this->isLoaded()) {
            throw new MappingNotLoadedException('The mapping is not loaded.');
        }

        return $this->containingPackage;
    }

    /**
     * Returns the errors that occurred during loading of the mapping.
     *
     * @return Exception[] The errors that occurred during loading. If the
     *                     returned array is empty, the mapping was loaded
     *                     successfully.
     *
     * @throws MappingNotLoadedException If the mapping is not loaded.
     */
    public function getLoadErrors()
    {
        if (!$this->isLoaded()) {
            throw new MappingNotLoadedException('The mapping is not loaded.');
        }

        return $this->loadErrors;
    }

    /**
     * Adds a conflict to the mapping.
     *
     * A mapping can refer to at most one conflict per conflicting repository
     * path. If the same conflict is added twice, the second addition is
     * ignored. If a different conflict is added for an existing repository
     * path, the previous conflict is removed before adding the new conflict
     * for the repository path.
     *
     * The repository path of the conflict must either be the repository path
     * of the mapping or any path within. If a conflict with a different path
     * is added, an exception is thrown.
     *
     * @param RepositoryPathConflict $conflict The conflict to be added.
     *
     * @throws MappingNotLoadedException If the mapping is not loaded.
     * @throws InvalidArgumentException If the path of the conflict is not
     *                                  within the repository path of the
     *                                  mapping.
     */
    public function addConflict(RepositoryPathConflict $conflict)
    {
        if (!$this->isLoaded()) {
            throw new MappingNotLoadedException('The mapping is not loaded.');
        }

        if (!Path::isBasePath($this->repositoryPath, $conflict->getRepositoryPath())) {
            throw new InvalidArgumentException(sprintf(
                'The conflicting path %s is not within the path %s of the '.
                'mapping.',
                $conflict->getRepositoryPath(),
                $this->repositoryPath
            ));
        }

        $repositoryPath = $conflict->getRepositoryPath();
        $previousConflict = isset($this->conflicts[$repositoryPath]) ? $this->conflicts[$repositoryPath] : null;

        if ($previousConflict === $conflict) {
            return;
        }

        if ($previousConflict) {
            $previousConflict->removeMapping($this);
        }

        $this->conflicts[$repositoryPath] = $conflict;
        $conflict->addMapping($this);

        $this->refreshState();
    }

    /**
     * Removes a conflict from the mapping.
     *
     * @param RepositoryPathConflict $conflict The conflict to remove.
     *
     * @throws MappingNotLoadedException If the mapping is not loaded.
     */
    public function removeConflict(RepositoryPathConflict $conflict)
    {
        if (!$this->isLoaded()) {
            throw new MappingNotLoadedException('The mapping is not loaded.');
        }

        $repositoryPath = $conflict->getRepositoryPath();

        if (!isset($this->conflicts[$repositoryPath]) || $conflict !== $this->conflicts[$repositoryPath]) {
            return;
        }

        unset($this->conflicts[$repositoryPath]);
        $conflict->removeMapping($this);

        $this->refreshState();
    }

    /**
     * Returns the conflicts of the mapping
     *
     * @return RepositoryPathConflict[] The conflicts.
     */
    public function getConflicts()
    {
        return array_values($this->conflicts);
    }

    /**
     * Returns all packages with conflicting resource mappings.
     *
     * @return PackageCollection The conflicting packages.
     */
    public function getConflictingPackages()
    {
        $collection = new PackageCollection();

        foreach ($this->conflicts as $conflict) {
            foreach ($conflict->getMappings() as $mapping) {
                if ($this === $mapping) {
                    continue;
                }

                $collection->add($mapping->getContainingPackage());
            }
        }

        return $collection;
    }

    /**
     * Returns all conflicting resource mappings.
     *
     * @return ResourceMapping[] The conflicting resource mappings.
     */
    public function getConflictingMappings()
    {
        $conflictingMappings = array();

        foreach ($this->conflicts as $conflict) {
            foreach ($conflict->getMappings() as $mapping) {
                if ($this === $mapping) {
                    continue;
                }

                $conflictingMappings[spl_object_hash($mapping)] = $mapping;
            }
        }

        return array_values($conflictingMappings);
    }

    /**
     * Returns the state of the mapping.
     *
     * @return int|null One of the {@link ResourceMappingState} constants or
     *                  `null` if the mapping is not loaded.
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * Returns whether the mapping is loaded.
     *
     * @return bool Returns `true` if {@link load()} was called.
     */
    public function isLoaded()
    {
        return null !== $this->state;
    }

    /**
     * Returns whether the mapping is enabled.
     *
     * @return bool Returns `true` if the state is
     *              {@link ResourceMappingState::ENABLED}.
     *
     * @see ResourceMappingState::ENABLED
     */
    public function isEnabled()
    {
        return ResourceMappingState::ENABLED === $this->state;
    }

    /**
     * Returns whether the path referenced by the mapping was not found.
     *
     * @return bool Returns `true` if the state is
     *              {@link ResourceMappingState::NOT_FOUND}.
     *
     * @see ResourceMappingState::NOT_FOUND
     */
    public function isNotFound()
    {
        return ResourceMappingState::NOT_FOUND === $this->state;
    }

    /**
     * Returns whether the mapping conflicts with a mapping in another package.
     *
     * @return bool Returns `true` if the state is
     *              {@link ResourceMappingState::CONFLICT}.
     *
     * @see ResourceMappingState::CONFLICT
     */
    public function isConflicting()
    {
        return ResourceMappingState::CONFLICT === $this->state;
    }

    private function refreshState()
    {
        if (count($this->conflicts) > 0) {
            $this->state = ResourceMappingState::CONFLICT;
        } elseif (0 === count($this->filesystemPaths)) {
            $this->state = ResourceMappingState::NOT_FOUND;
        } else {
            $this->state = ResourceMappingState::ENABLED;
        }
    }

    private function makeAbsolute($relativePath, Package $containingPackage, PackageCollection $packages)
    {
        // Reference to install path of other package
        if ('@' !== $relativePath[0] || false === ($pos = strpos($relativePath, ':'))) {
            return $containingPackage->getInstallPath().'/'.$relativePath;
        }

        $refPackageName = substr($relativePath, 1, $pos - 1);

        if (!$packages->contains($refPackageName)) {
            throw new NoSuchPackageException(sprintf(
                'The package "%s" referenced in the resource path "%s" was not '.
                'found. Maybe the package is not installed?',
                $refPackageName,
                $relativePath
            ));
        }

        $refPackage = $packages->get($refPackageName);

        return $refPackage->getInstallPath().'/'.substr($relativePath, $pos + 1);
    }

    private function assertFileExists($absolutePath, $relativePath, Package $containingPackage)
    {
        if (!file_exists($absolutePath)) {
            throw new FileNotFoundException(sprintf(
                'The path %s mapped to %s by package "%s" does not exist.',
                $relativePath,
                $this->repositoryPath,
                $containingPackage->getName()
            ));
        }
    }
}
