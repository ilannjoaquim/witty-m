<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\CategoryBundle\Entity\Category;
use Mautic\LeadBundle\Entity\Tag;
use Mautic\ProjectBundle\Entity\Project;
use MauticPlugin\WittyBundle\Entity\WittyRoom;
use MauticPlugin\WittyBundle\Service\Taxonomy\TaxonomyOptionsProvider;
use MauticPlugin\WittyBundle\Service\Tool\Tools\UpdateMeetRoomTool;
use MauticPlugin\WittyBundle\Service\Videoconference\RoomManager;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

/**
 * Deux points fragiles specifiques a cet outil : (1) une salle creee avant ce
 * correctif (ou directement sur plugNmeet) n'a pas de WittyRoom, il faut donc
 * en creer une a la volee plutot que d'echouer ; (2) omettre un champ doit
 * laisser sa valeur actuelle intacte, le fournir vide doit l'effacer — une
 * inversion des deux serait une perte de donnees silencieuse.
 */
class UpdateMeetRoomToolTest extends TestCase
{
    public function testRequiresAtLeastOneFieldToChange(): void
    {
        $rooms = $this->createMock(RoomManager::class);
        $rooms->expects($this->never())->method('save');

        $output = $this->tool($rooms, $this->createMock(TaxonomyOptionsProvider::class), true)
            ->execute(['room_id' => 'room-1']);

        $this->assertSame('error', $output['status']);
    }

    public function testCreatesTheWittyRoomOnTheFlyWhenNoneExistsYet(): void
    {
        $category = new Category();

        $rooms = $this->createMock(RoomManager::class);
        $rooms->method('findByRoomId')->with('room-1')->willReturn(null);
        $rooms->expects($this->once())->method('save')->with($this->callback(
            static fn (WittyRoom $room): bool => 'room-1' === $room->getRoomId(),
        ));

        $taxonomy = $this->createMock(TaxonomyOptionsProvider::class);
        $taxonomy->method('resolveCategory')->with(9)->willReturn($category);

        $output = $this->tool($rooms, $taxonomy, false)->execute(['room_id' => 'room-1', 'category_id' => 9]);

        $this->assertSame('ok', $output['status']);
    }

    public function testOmittingAFieldLeavesItUntouched(): void
    {
        $existingCategory = new Category();
        $existingProjects = new ArrayCollection([new Project()]);
        $existingTags      = new ArrayCollection([$this->tag()]);

        $room = new WittyRoom();
        $room->setRoomId('room-1');
        $room->setCategory($existingCategory);
        $room->setProjects($existingProjects);
        $room->setTags($existingTags);

        $newCategory = new Category();

        $rooms = $this->createMock(RoomManager::class);
        $rooms->method('findByRoomId')->willReturn($room);
        $rooms->expects($this->once())->method('save');

        $taxonomy = $this->createMock(TaxonomyOptionsProvider::class);
        $taxonomy->method('resolveCategory')->willReturn($newCategory);
        $taxonomy->expects($this->never())->method('resolveProjects');
        $taxonomy->expects($this->never())->method('resolveTags');

        // Seul category_id est fourni : projects/tags ne doivent pas bouger.
        $this->tool($rooms, $taxonomy, false)->execute(['room_id' => 'room-1', 'category_id' => 5]);

        $this->assertSame($newCategory, $room->getCategory());
        $this->assertSame($existingProjects, $room->getProjects());
        $this->assertSame($existingTags, $room->getTags());
    }

    public function testExplicitEmptyArraysClearProjectsAndTags(): void
    {
        $room = new WittyRoom();
        $room->setRoomId('room-1');
        $room->setProjects(new ArrayCollection([new Project()]));
        $room->setTags(new ArrayCollection([$this->tag()]));

        $rooms = $this->createMock(RoomManager::class);
        $rooms->method('findByRoomId')->willReturn($room);

        $taxonomy = $this->createMock(TaxonomyOptionsProvider::class);
        $taxonomy->method('resolveProjects')->with([])->willReturn(new ArrayCollection());
        $taxonomy->method('resolveTags')->with([])->willReturn(new ArrayCollection());

        $this->tool($rooms, $taxonomy, false)->execute(['room_id' => 'room-1', 'project_ids' => [], 'tag_ids' => []]);

        $this->assertCount(0, $room->getProjects());
        $this->assertCount(0, $room->getTags());
    }

    public function testCategoryIdZeroClearsTheCurrentCategory(): void
    {
        $room = new WittyRoom();
        $room->setRoomId('room-1');
        $room->setCategory(new Category());

        $rooms = $this->createMock(RoomManager::class);
        $rooms->method('findByRoomId')->willReturn($room);

        $taxonomy = $this->createMock(TaxonomyOptionsProvider::class);
        $taxonomy->method('resolveCategory')->with(null)->willReturn(null);

        $this->tool($rooms, $taxonomy, false)->execute(['room_id' => 'room-1', 'category_id' => 0]);

        $this->assertNull($room->getCategory());
    }

    public function testRequiresConfirmationBeforeSaving(): void
    {
        $rooms = $this->createMock(RoomManager::class);
        $rooms->method('findByRoomId')->willReturn(new WittyRoom());
        $rooms->expects($this->never())->method('save');

        $output = $this->tool($rooms, $this->createMock(TaxonomyOptionsProvider::class), true)
            ->execute(['room_id' => 'room-1', 'category_id' => 5]);

        $this->assertSame('confirmation_required', $output['status']);
    }

    private function tag(): Tag
    {
        return new Tag();
    }

    private function tool(RoomManager $rooms, TaxonomyOptionsProvider $taxonomy, bool $requiresConfirmation): UpdateMeetRoomTool
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn($requiresConfirmation);

        return new UpdateMeetRoomTool($rooms, $taxonomy, $config);
    }
}
