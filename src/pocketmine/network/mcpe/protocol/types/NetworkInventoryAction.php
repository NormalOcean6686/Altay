<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol\types;

use pocketmine\inventory\EnchantInventory;
use pocketmine\inventory\FakeInventory;
use pocketmine\inventory\FakeResultInventory;
use pocketmine\inventory\transaction\action\CreativeInventoryAction;
use pocketmine\inventory\transaction\action\DropItemAction;
use pocketmine\inventory\transaction\action\EnchantAction;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\inventory\transaction\action\InventoryAction;
use pocketmine\item\Item;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\Player;

class NetworkInventoryAction{
	public const SOURCE_CONTAINER = 0;
	public const SOURCE_GLOBAL_INVENTORY = 1;
	public const SOURCE_WORLD = 2; //drop/pickup item entity
	public const SOURCE_CREATIVE = 3;
	public const SOURCE_UNTRACKED_INTERACTION_UI = 100;
	public const SOURCE_TODO = 99999;

	/**
	 * Fake window IDs for the SOURCE_TODO type (99999)
	 *
	 * These identifiers are used for inventory source types which are not currently implemented server-side in MCPE.
	 * As a general rule of thumb, anything that doesn't have a permanent inventory is client-side. These types are
	 * to allow servers to track what is going on in client-side windows.
	 *
	 * Expect these to change in the future.
	 */
	public const SOURCE_TYPE_CRAFTING_RESULT = -4;
	public const SOURCE_TYPE_CRAFTING_USE_INGREDIENT = -5;

	public const SOURCE_TYPE_FAKE_INVENTORY_INPUT = -10;
	public const SOURCE_TYPE_FAKE_INVENTORY_MATERIAL = -11;
	public const SOURCE_TYPE_FAKE_INVENTORY_RESULT = -12;

	public const SOURCE_TYPE_ENCHANT_OUTPUT = -17;

	public const SOURCE_TYPE_TRADING_INPUT_1 = -20;
	public const SOURCE_TYPE_TRADING_INPUT_2 = -21;
	public const SOURCE_TYPE_TRADING_USE_INPUTS = -22;
	public const SOURCE_TYPE_TRADING_OUTPUT = -23;

	/** Any client-side window dropping its contents when the player closes it */
	public const SOURCE_TYPE_CONTAINER_DROP_CONTENTS = -100;

	public const ACTION_MAGIC_SLOT_CREATIVE_DELETE_ITEM = 0;
	public const ACTION_MAGIC_SLOT_CREATIVE_CREATE_ITEM = 1;

	public const ACTION_MAGIC_SLOT_DROP_ITEM = 0;
	public const ACTION_MAGIC_SLOT_PICKUP_ITEM = 1;

	/** @var int */
	public $sourceType;
	/** @var int */
	public $windowId;
	/** @var int */
	public $sourceFlags = 0;
	/** @var int */
	public $inventorySlot;
	/** @var Item */
	public $oldItem;
	/** @var Item */
	public $newItem;

	/**
	 * @return $this
	 */
	public function read(InventoryTransactionPacket $packet){
		$this->sourceType = $packet->getUnsignedVarInt();

		switch($this->sourceType){
			case self::SOURCE_CONTAINER:
				$this->windowId = $packet->getVarInt();
				break;
			case self::SOURCE_GLOBAL_INVENTORY: // TODO: find out what this is used for
				break;
			case self::SOURCE_WORLD:
				$this->sourceFlags = $packet->getUnsignedVarInt();
				break;
			case self::SOURCE_CREATIVE:
				break;
			case self::SOURCE_UNTRACKED_INTERACTION_UI:
			case self::SOURCE_TODO:
				$this->windowId = $packet->getVarInt();
				switch($this->windowId){
					/** @noinspection PhpMissingBreakStatementInspection */
					case self::SOURCE_TYPE_CRAFTING_RESULT:
						$packet->isFinalCraftingPart = true;
					case self::SOURCE_TYPE_CRAFTING_USE_INGREDIENT:
						$packet->isCraftingPart = true;
						break;
				}
				break;
			default:
				throw new \UnexpectedValueException("Unknown inventory action source type $this->sourceType");
		}

		$this->inventorySlot = $packet->getUnsignedVarInt();
		$this->oldItem = $packet->getSlot();
		$this->newItem = $packet->getSlot();

		return $this;
	}

	/**
	 * @return void
	 */
	public function write(InventoryTransactionPacket $packet){
		$packet->putUnsignedVarInt($this->sourceType);

		switch($this->sourceType){
			case self::SOURCE_CONTAINER:
				$packet->putVarInt($this->windowId);
				break;
			case self::SOURCE_GLOBAL_INVENTORY:
				break;
			case self::SOURCE_WORLD:
				$packet->putUnsignedVarInt($this->sourceFlags);
				break;
			case self::SOURCE_CREATIVE:
				break;
			case self::SOURCE_UNTRACKED_INTERACTION_UI:
			case self::SOURCE_TODO:
				$packet->putVarInt($this->windowId);
				break;
			default:
				throw new \InvalidArgumentException("Unknown inventory action source type $this->sourceType");
		}

		$packet->putUnsignedVarInt($this->inventorySlot);
		$packet->putSlot($this->oldItem);
		$packet->putSlot($this->newItem);
	}

	/**
	 * @return InventoryAction|null
	 *
	 * @throws \UnexpectedValueException
	 */
	public function createInventoryAction(Player $player){
		switch($this->sourceType){
			case self::SOURCE_CONTAINER:
				$window = $player->getWindow($this->windowId);
				if($window !== null){
					return new SlotChangeAction($window, $this->inventorySlot, $this->oldItem, $this->newItem);
				}

				throw new \UnexpectedValueException("Player " . $player->getName() . " has no open container with window ID $this->windowId");
			case self::SOURCE_WORLD:
				if($this->inventorySlot !== self::ACTION_MAGIC_SLOT_DROP_ITEM){
					throw new \UnexpectedValueException("Only expecting drop-item world actions from the client!");
				}

				return new DropItemAction($this->newItem);
			case self::SOURCE_CREATIVE:
				switch($this->inventorySlot){
					case self::ACTION_MAGIC_SLOT_CREATIVE_DELETE_ITEM:
						$type = CreativeInventoryAction::TYPE_DELETE_ITEM;
						break;
					case self::ACTION_MAGIC_SLOT_CREATIVE_CREATE_ITEM:
						$type = CreativeInventoryAction::TYPE_CREATE_ITEM;
						break;
					default:
						throw new \UnexpectedValueException("Unexpected creative action type $this->inventorySlot");

				}

				return new CreativeInventoryAction($this->oldItem, $this->newItem, $type);
			case self::SOURCE_UNTRACKED_INTERACTION_UI:
			case self::SOURCE_TODO:
				$window = $player->findWindow(FakeInventory::class);

				switch($this->windowId){
					case self::SOURCE_TYPE_CONTAINER_DROP_CONTENTS: //TODO: this type applies to all fake windows, not just crafting
						return new SlotChangeAction($window ?? $player->getCraftingGrid(), $this->inventorySlot, $this->oldItem, $this->newItem);
					case self::SOURCE_TYPE_CRAFTING_RESULT:
					case self::SOURCE_TYPE_CRAFTING_USE_INGREDIENT:
						return null;
					case self::SOURCE_TYPE_ENCHANT_OUTPUT:
						if($window instanceof EnchantInventory){
							return new EnchantAction($window, $this->inventorySlot, $this->oldItem, $this->newItem);
						}else{
							if($window === null){
								throw new \InvalidStateException("Window not found");
							}else{
								throw new \InvalidStateException("Unexpected fake inventory given. Expected " . EnchantInventory::class . " , given " . get_class($window));
							}
						}
					case self::SOURCE_TYPE_FAKE_INVENTORY_INPUT:
					case self::SOURCE_TYPE_FAKE_INVENTORY_MATERIAL:
						if($window instanceof FakeResultInventory){
							if(!$window->isSlotEmpty($window->getResultSlot())){
								return null;
							}
						}

						return new SlotChangeAction($window, $this->inventorySlot, $this->oldItem, $this->newItem);
					case self::SOURCE_TYPE_FAKE_INVENTORY_RESULT:
						if($window instanceof FakeResultInventory){
							if(!$window->onResult($player, $this->oldItem)){
								return null;
							}
						}

						return new SlotChangeAction($window, $this->inventorySlot, $this->oldItem, $this->newItem);
				}

				throw new \UnexpectedValueException("Player " . $player->getName() . " has no open container with window ID $this->windowId");
			default:
				throw new \UnexpectedValueException("Unknown inventory source type $this->sourceType");
		}
	}
}
