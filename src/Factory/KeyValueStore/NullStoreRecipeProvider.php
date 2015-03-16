<?php

/*
 * This file is part of the puli/repository-manager package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\RepositoryManager\Factory\KeyValueStore;

use Puli\RepositoryManager\Factory\BuildRecipe;
use Puli\RepositoryManager\Factory\BuildRecipeProvider;
use Puli\RepositoryManager\Factory\ProviderFactory;

/**
 * Creates the build recipe for a {@link NullStore}.
 *
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class NullStoreRecipeProvider implements BuildRecipeProvider
{
    /**
     * {@inheritdoc}
     */
    public function getRecipe($varName, $outputDir, $rootDir, array $options, ProviderFactory $providerFactory)
    {
        $recipe = new BuildRecipe();
        $recipe->addImport('Webmozart\KeyValueStore\NullStore');
        $recipe->addVarDeclaration($varName, $varName.' = new NullStore();');

        return $recipe;
    }
}