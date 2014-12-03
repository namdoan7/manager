<?php

/*
 * This file is part of the Puli Repository Manager package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\RepositoryManager\Tests\Package;

use Puli\RepositoryManager\Package\PackageFile\RootPackageFile;
use Puli\RepositoryManager\Package\RootPackage;

/**
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class RootPackageTest extends \PHPUnit_Framework_TestCase
{
    public function testPackageName()
    {
        $packageFile = new RootPackageFile('name');
        $package = new RootPackage($packageFile, '/path');

        $this->assertSame('name', $package->getName());
    }

    public function testPackageNameSetToDefaultIfEmpty()
    {
        $packageFile = new RootPackageFile();
        $package = new RootPackage($packageFile, '/path');

        $this->assertSame('__root__', $package->getName());
    }
}