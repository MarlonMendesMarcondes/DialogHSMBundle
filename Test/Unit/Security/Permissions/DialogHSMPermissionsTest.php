<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Test\Unit\Security\Permissions;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\DialogHSMBundle\Security\Permissions\DialogHSMPermissions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DialogHSMPermissionsTest extends TestCase
{
    /** @var MockObject&CoreParametersHelper */
    private MockObject $coreParametersHelper;

    private DialogHSMPermissions $permissions;

    protected function setUp(): void
    {
        $this->coreParametersHelper = $this->createMock(CoreParametersHelper::class);
        $this->coreParametersHelper->method('all')->willReturn([]);

        $this->permissions = new DialogHSMPermissions($this->coreParametersHelper);
    }

    public function testGetNameReturnsDialoghsm(): void
    {
        $this->assertSame('dialoghsm', $this->permissions->getName());
    }

    public function testIsSupported_numbersView(): void
    {
        $this->assertTrue($this->permissions->isSupported('numbers', 'view'));
    }

    public function testIsSupported_numbersEdit(): void
    {
        $this->assertTrue($this->permissions->isSupported('numbers', 'edit'));
    }

    public function testIsSupported_numbersCreate(): void
    {
        $this->assertTrue($this->permissions->isSupported('numbers', 'create'));
    }

    public function testIsSupported_numbersDelete(): void
    {
        $this->assertTrue($this->permissions->isSupported('numbers', 'delete'));
    }

    public function testIsSupported_numbersPublish(): void
    {
        $this->assertTrue($this->permissions->isSupported('numbers', 'publish'));
    }

    public function testIsSupported_numbersFull(): void
    {
        $this->assertTrue($this->permissions->isSupported('numbers', 'full'));
    }

    public function testIsNotSupported_unknownLevel(): void
    {
        $this->assertFalse($this->permissions->isSupported('numbers', 'nonexistent'));
    }

    public function testIsNotSupported_unknownBundle(): void
    {
        $this->assertFalse($this->permissions->isSupported('unknown', 'view'));
    }

    public function testBuildFormDoesNotThrow(): void
    {
        $builder = $this->createMock(\Symfony\Component\Form\FormBuilderInterface::class);
        $builder->method('add')->willReturnSelf();

        $this->permissions->buildForm($builder, [], []);

        $this->addToAssertionCount(1);
    }
}
