<?php

class Level{
	/**
	 * @var Config
	 */
	public $entities;
	/**
	 * This is an array of entities in this world. 
	 * @var Entity[]
	 */
	public $entityList;
	
	public $entityListPositioned = [];
	public $entitiesInLove = [];
	
	/**
	 * @var Player[]
	 */
	public $players = [];
	
	public $tiles, $blockUpdates, $nextSave, $level, $mobSpawner, $totalMobsAmount = 0;
	private $time, $startCheck, $startTime, $server, $name, $usedChunks, $changedBlocks, $changedCount, $stopTime;
	
	public $randInt1, $randInt2;
	public $queuedBlockUpdates = [];
	
	public static $randomUpdateBlocks = [
		FIRE => true,
		FARMLAND => true,
		GLOWING_REDSTONE_ORE => true,
		BEETROOT_BLOCK => true,
		SAPLING => true,
		CACTUS => true,
		CARROT_BLOCK => true,
		MELON_STEM => true,
		POTATO_BLOCK => true,
		PUMPKIN_STEM => true,
		SUGARCANE_BLOCK => true,
		WHEAT_BLOCK => true,
		DIRT => true,
		GRASS => true,
		ICE => true,
		LEAVES => true
	];
	
	public function __construct(PMFLevel $level, Config $entities, Config $tiles, Config $blockUpdates, $name){
		$this->server = ServerAPI::request();
		$this->level = $level;
		$this->level->level = $this;
		$this->entityList = [];
		$this->entities = $entities;
		$this->tiles = $tiles;
		$this->blockUpdates = $blockUpdates;
		$this->startTime = $this->time = (int) $this->level->getData("time");
		$this->nextSave = $this->startCheck = microtime(true);
		$this->nextSave += 90;
		$this->stopTime = false;
		$this->server->schedule(15, [$this, "checkThings"], [], true);
		$this->server->schedule(20 * 13, [$this, "checkTime"], [], true);
		$this->name = $name;
		$this->usedChunks = [];
		$this->changedBlocks = [];
		$this->changedCount = [];
		$this->mobSpawner = new MobSpawner($this);
		$this->randInt1 = 0x283AE83; //it is static in 0.1, and i dont care is it in 0.8
		$this->randInt2 = 0x3C6EF35F;
	}

	public function close(){
		$this->__destruct();
	}
	
	public function isTopSolidBlocking($x, $y, $z){
		$idmeta = $this->level->getBlock($x, $y, $z);
		$id = $idmeta[0];
		$meta = $idmeta[1];
		if($id == 0) return false;
		if(StaticBlock::getIsTransparent($id)) return false;
		
		return true;
	}
	
	//TODO mayPlace
	public function isLavaInBB($aabb){
		$minX = floor($aabb->minX);
		$maxX = floor($aabb->maxX + 1);
		$minY = floor($aabb->minY);
		$maxY = floor($aabb->maxY + 1);
		$minZ = floor($aabb->minZ);
		$maxZ = floor($aabb->maxZ + 1);
		
		for($x = $minX; $x < $maxX; ++$x){
			for($y = $minY; $y < $maxY; ++$y){
				for($z = $minZ; $z < $maxZ; ++$z){
					$blockId = $this->level->getBlockID($x, $y, $z);
					
					if($blockId == LAVA || $blockId == STILL_LAVA) return true;
				}
			}
		}
		
		return false;
	}
	
	public function handleMaterialAcceleration(AxisAlignedBB $aabb, $materialType, Entity $entity){
		$minX = floor($aabb->minX);
		$maxX = ceil($aabb->maxX);
		$minY = floor($aabb->minY);
		$maxY = ceil($aabb->maxY);
		$minZ = floor($aabb->minZ);
		$maxZ = ceil($aabb->maxZ);
		
		//1.5.2 checks that all chunks exist, not needed here i think
		
		$appliedVelocity = false;
		$velocityVec = new Vector3(0, 0, 0);
		
		for($x = $minX; $x < $maxX; ++$x){
			for($y = $minY; $y < $maxY; ++$y){
				for($z = $minZ; $z < $maxZ; ++$z){
					[$block, $meta] = $this->level->getBlock($x, $y, $z);
					if(($materialType == 0 && ($block == WATER || $block == STILL_WATER)) || ($materialType == 1 && ($block == LAVA || $block == STILL_LAVA))){ //TODO better material system
						$v16 = ($y + 1) - LiquidBlock::getPercentAir($meta);
						if($maxY >= $v16){
							$appliedVelocity = true;
							Block::$class[$block]::addVelocityToEntity($this, $x, $y, $z, $entity, $velocityVec);
						}
					}
				}
			}
		}
		
		if($velocityVec->length() > 0){ //also checks is player flying
			$velocityVec = $velocityVec->normalize(); //TODO do not use vec methods
			$v18 = 0.014;
			$entity->speedX += $velocityVec->x * $v18;
			$entity->speedY += $velocityVec->y * $v18;
			$entity->speedZ += $velocityVec->z * $v18;
		}
		
		return $appliedVelocity;
	}
	
	/**
	 * @param Entity $e
	 * @param AxisAlignedBB $aABB
	 * @return AxisAlignedBB[]
	 */
	public function getCubes(Entity $e, AxisAlignedBB $aABB) {
		$aABBs = [];
		$x0 = floor($aABB->minX);
		$x1 = ceil($aABB->maxX);
		$y0 = floor($aABB->minY);
		$y1 = ceil($aABB->maxY);
		$z0 = floor($aABB->minZ);
		$z1 = ceil($aABB->maxZ);
		
		for($x = $x0; $x <= $x1; ++$x) {
			for($y = $y0; $y <= $y1; ++$y) {
				for($z = $z0; $z <= $z1; ++$z) {
					$bid = $this->level->getBlockID($x, $y, $z);
					if($bid > 0){
						$blockBounds = Block::$class[$bid]::getCollisionBoundingBoxes($this, $x, $y, $z, $e); //StaticBlock::getBoundingBoxForBlockCoords($b, $x, $y, $z);
						foreach($blockBounds as $blockBound){
							$aABBs[] = $blockBound;
						}
					}
				}
			}
		}
		
		return $aABBs;
	}
	
	public function __destruct(){
		if(isset($this->level)){
			$this->save(false, false);
			$this->level->close();
			unset($this->level);
		}
		unset($this->mobSpawner->level);
	}
	
	public function isDay(){
		return $this->getTime() % 19200 < TimeAPI::$phases["sunset"];
	}
	public function isNight(){
		$t = $this->getTime() % 19200;
		return $t < TimeAPI::$phases["sunrise"] && $t > TimeAPI::$phases["sunset"];
	}
	public function save($force = false, $extra = true){
		if(!isset($this->level)){
			return false;
		}
		if($this->server->saveEnabled === false and $force === false){
			return;
		}

		if($extra !== false){
			$entities = [];
			foreach($this->entityList as $entity){
				if($entity instanceof Entity){
					$entities[] = $entity->createSaveData();
				}
			}
			$this->entities->setAll($entities);
			$this->entities->save();
			$tiles = [];
			foreach($this->server->api->tile->getAll($this) as $tile){
				$tiles[] = $tile->data;
			}
			$this->tiles->setAll($tiles);
			$this->tiles->save();

			$blockUpdates = [];
			$updates = $this->server->query("SELECT x,y,z,type,delay FROM blockUpdates WHERE level = '" . $this->getName() . "';");
			if($updates !== false and $updates !== true){
				$timeu = microtime(true);
				while(($bupdate = $updates->fetchArray(SQLITE3_ASSOC)) !== false){
					$bupdate["delay"] = max(1, ($bupdate["delay"] - $timeu) * 20);
					$blockUpdates[] = $bupdate;
				}
			}

			$this->blockUpdates->setAll($blockUpdates);
			$this->blockUpdates->save();

		}

		$this->level->setData("time", (int) $this->time);
		$this->level->doSaveRound();
		$this->level->saveData();
		$this->nextSave = microtime(true) + 45;
	}

	public function getName(){
		return $this->name;//return $this->level->getData("name");
	}

	public function useChunk($X, $Z, Player $player){
		if(!isset($this->usedChunks[$X . "." . $Z])){
			$this->usedChunks[$X . "." . $Z] = [];
		}
		$this->usedChunks[$X . "." . $Z][$player->CID] = true;
		if(isset($this->level)){
			$this->level->loadChunk($X, $Z);
		}
	}

	public function freeAllChunks(Player $player){
		foreach($this->usedChunks as $i => $c){
			unset($this->usedChunks[$i][$player->CID]);
		}
	}

	public function freeChunk($X, $Z, Player $player){
		unset($this->usedChunks[$X . "." . $Z][$player->CID]);
	}
	
	public function checkCollisionsFor(Entity $e){
		if($e->level->getName() != $this->getName()){
			return false; //not the same world
		}
		foreach($this->entityList as $e1){
			if($e->boundingBox->intersectsWith($e1->boundingBox) && $e1->isCollidable){
				$e->onCollideWith($e1);
				$e1->onCollideWith($e);
			}
		}
	}
	public function isObstructed($e){
		
	}
	
	public function checkSleep(){ //TODO events?
		if(count($this->players) == 0) return false;
		if($this->server->api->time->getPhase($this->level)  === "night"){ //TODO vanilla
			foreach($this->players as $p){
				if($p->isSleeping == false || $p->sleepingTime < 100){
					return false;
				}
			}
			$this->server->api->time->set("day", $this->level);
		}
		foreach($this->players as $p){
			$p->stopSleep();
		}
	}
	
	public function checkThings(){
		if(!isset($this->level)){
			return false;
		}
		$now = microtime(true);
		$this->players = $this->server->api->player->getAll($this);
		
		if(count($this->changedCount) > 0){
			arsort($this->changedCount);
			$resendChunks = [];
			foreach($this->changedCount as $index => $count){
				if($count < 582){//Optimal value, calculated using the relation between minichunks and single packets
					break;
				}
				foreach($this->players as $p){
					unset($p->chunksLoaded[$index]);
				}
				unset($this->changedBlocks[$index]);
			}
			$this->changedCount = [];

			if(count($this->changedBlocks) > 0){
				foreach($this->changedBlocks as $i => $blocks){
					foreach($blocks as $b){
						$pk = new UpdateBlockPacket;
						$pk->x = $b[0];
						$pk->y = $b[1];
						$pk->z = $b[2];
						$pk->block = $b[3];
						$pk->meta = $b[4];
						$this->server->api->player->broadcastPacket($this->players, $pk);
					}
					unset($this->changedBlocks[$i]);
				}
				$this->changedBlocks = [];
			}
		}

		if($this->nextSave < $now){
			$this->save(false, false);
		}
	}

	public function isSpawnChunk($X, $Z){
		$spawnX = $this->level->getData("spawnX") >> 4;
		$spawnZ = $this->level->getData("spawnZ") >> 4;
		return abs($X - $spawnX) <= 1 and abs($Z - $spawnZ) <= 1;
	}

	public function getBlockRaw(Vector3 $pos){
		$b = $this->level->getBlock($pos->x, $pos->y, $pos->z);
		return BlockAPI::get($b[0], $b[1], new Position($pos->x, $pos->y, $pos->z, $this));
	}

	public function setBlockRaw(Vector3 $pos, Block $block, $direct = true, $send = true){
		if(($ret = $this->level->setBlock($pos->x, $pos->y, $pos->z, $block->getID(), $block->getMetadata())) === true and $send !== false){
			if($direct === true){
				$this->addBlockToSendQueue($pos->x, $pos->y, $pos->z, $block->id, $block->meta);
			}elseif($direct === false){
				if(!($pos instanceof Position)){
					$pos = new Position($pos->x, $pos->y, $pos->z, $this);
				}
				$block->position($pos);
				$i = ($pos->x >> 4) . ":" . ($pos->y >> 4) . ":" . ($pos->z >> 4);
				if(!isset($this->changedBlocks[$i])){
					$this->changedBlocks[$i] = [];
					$this->changedCount[$i] = 0;
				}
				$this->changedBlocks[$i][] = [$block->x, $block->y, $block->z, $block->id, $block->getMetadata()];
				++$this->changedCount[$i];
			}
		}
		return $ret;
	}
	public function fastSetBlockUpdateMeta($x, $y, $z, $meta, $updateBlock = false){
		$this->level->setBlockDamage($x, $y, $z, $meta);
		$this->addBlockToSendQueue($x, $y, $z, $this->level->getBlockID($x, $y, $z), $meta);
		if($updateBlock){
			$this->server->api->block->blockUpdateAround(new Position($x, $y, $z, $this), BLOCK_UPDATE_NORMAL, 1);
		}
	}
	
	public function fastSetBlockUpdate($x, $y, $z, $id, $meta, $updateBlocksAround = false){
		$this->level->setBlock($x, $y, $z, $id, $meta);
		$this->addBlockToSendQueue($x, $y, $z, $id, $meta);
		if($updateBlocksAround){
			$this->server->api->block->blockUpdateAround(new Position($x, $y, $z, $this), BLOCK_UPDATE_NORMAL, 1);
		}
	}
	
	public function onTick(PocketMinecraftServer $server, $currentTime){
		if(!$this->stopTime) ++$this->time;
		for($cX = 0; $cX < 16; ++$cX){
			for($cZ = 0; $cZ < 16; ++$cZ){
				$index = $this->level->getIndex($cX, $cZ);
				if(!isset($this->level->chunks[$index]) || $this->level->chunks[$index] === false) continue;
				for($c = 0; $c <= 20; ++$c){
					$xyz = mt_rand(0, 0xffffffff) >> 2;
					$x = $xyz & 0xf;
					$z = ($xyz >> 8) & 0xf; //TODO might be possible to make some micro optmizations
					$y = ($xyz >> 16) & 0x7f;
					$id = $this->level->fastGetBlockID($cX, $y >> 4, $cZ, $x, $y & 0xf, $z, $index); //$this->level->getBlockID(($cX << 4) + $x, $y, $z + ($cZ << 4));
					if(isset(self::$randomUpdateBlocks[$id])){
						$cl = Block::$class[$id];
						$cl::onRandomTick($this, ($cX << 4) + $x, $y, $z + ($cZ << 4));
					}
				}
			}
		}
		$this->totalMobsAmount = 0;
		$post = [];
		foreach($this->entityList as $k => $e){
			
			if(!($e instanceof Entity)){
				unset($this->entityList[$k]);
				unset($this->server->entities[$k]);
				//TODO try to remove from $entityListPositioned?
				continue;
			}
			$curChunkX = (int)$e->x >> 4;
			$curChunkZ = (int)$e->z >> 4;
			if($e->class === ENTITY_MOB && !$e->isPlayer()){
				++$this->totalMobsAmount;
			}
			if($e->isPlayer() || $e->needsUpdate){
				$e->update($currentTime);
				if(!$e->isPlayer()) $post[] = $k;
			}
			
			if($e instanceof Entity){
				$newChunkX = (int)$e->x >> 4;
				$newChunkZ = (int)$e->z >> 4;
				if($e->chunkX != $newChunkX || $e->chunkZ != $newChunkZ){
					$oldIndex = "{$e->chunkX} {$e->chunkZ}";
					unset($this->entityListPositioned[$oldIndex][$e->eid]);
					
					if($e->level == $this){
						$e->chunkX = $newChunkX;
						$e->chunkZ = $newChunkZ;
						$newIndex = "$newChunkX $newChunkZ";
						$this->entityListPositioned[$newIndex][$e->eid] = $e->eid;
					}
				}
				if($e->level != $this && isset($this->entityListPositioned["$curChunkX $curChunkZ"])){
					unset($this->entityListPositioned["$curChunkX $curChunkZ"][$e->eid]);
				}else if($curChunkX != $newChunkX || $curChunkZ != $newChunkZ){
					$index = "$curChunkX $curChunkZ"; //while creating index like $curChunkX << 32 | $curChunkZ is faster, placing it inside list is slow
					$newIndex = "$newChunkX $newChunkZ";
					unset($this->entityListPositioned[$index][$e->eid]);
					$this->entityListPositioned[$newIndex][$e->eid] = $e->eid; //set to e->eid to avoid possible memory leaks
				}
			}else if(isset($this->entityListPositioned["$curChunkX $curChunkZ"])){
				unset($this->entityListPositioned["$curChunkX $curChunkZ"][$k]);
			}
		}
		
		$this->checkSleep();
		
		if($server->ticks % 40 === 0){ //40 ticks delay
			$this->mobSpawner->handle();
		}
		
		
		foreach($this->players as $player){
			foreach($post as $eid){
				$e = $this->entityList[$eid] ?? false;
				if(!($e instanceof Entity)){
					continue;
				}
				$player->addEntityMovementUpdateToQueue($e);
			}
			$player->sendEntityMovementUpdateQueue();
			
			foreach($this->queuedBlockUpdates as $ind => $update){
				$x = $update[0];
				$y = $update[1];
				$z = $update[2];
				$idmeta = $this->level->getBlock($x, $y, $z);
				$id = $idmeta[0];
				$meta = $idmeta[1];
				$player->addBlockUpdateIntoQueue($x, $y, $z, $id, $meta);
			}
			$player->sendBlockUpdateQueue();
		}
		
		$this->queuedBlockUpdates = [];
	}
	
	public function isBoundingBoxOnFire(AxisAlignedBB $bb){
		$minX = floor($bb->minX);
		$maxX = floor($bb->maxX + 1);
		$minY = floor($bb->minY);
		$maxY = floor($bb->maxY + 1);
		$minZ = floor($bb->minZ);
		$maxZ = floor($bb->maxZ + 1);
		
		for($x = $minX; $x < $maxX; ++$x){
			for($y = $minY; $y < $maxY; ++$y){
				for($z = $minZ; $z < $maxZ; ++$z){
					$blockAt = $this->level->getBlockID($x, $y, $z);
					if($blockAt == FIRE || $blockAt == STILL_LAVA || $blockAt == LAVA) return true;
				}
			}
		}
		
		return false;
	}
	public function getEntitiesInAABBOfType(AxisAlignedBB $bb, $class){
		$minChunkX = ((int)($bb->minX)) >> 4;
		$minChunkZ = ((int)($bb->minZ)) >> 4;
		$maxChunkX = ((int)($bb->maxX)) >> 4;
		$maxChunkZ = ((int)($bb->maxZ)) >> 4;
		$ents = [];
		for($chunkX = $minChunkX; $chunkX <= $maxChunkX; ++$chunkX){
			for($chunkZ = $minChunkZ; $chunkZ <= $maxChunkZ; ++$chunkZ){
				$ind = "$chunkX $chunkZ";
				foreach($this->entityListPositioned[$ind] ?? [] as $ind2 => $entid){
					if(isset($this->entityList[$entid]) && $this->entityList[$entid] instanceof Entity && $this->entityList[$entid]->class === $class && $this->entityList[$entid]->boundingBox->intersectsWith($bb)){
						$ents[$entid] = $this->entityList[$entid];
					}else if(!isset($this->entityList[$entid])){
						ConsoleAPI::debug("Removing entity from level array at index $ind/$ind2: $entid");
						unset($this->entityListPositioned[$ind][$ind2]);
					}
				}
			}
		}
		return $ents;
	}
	
	public function getEntitiesInAABB(AxisAlignedBB $bb){
		$minChunkX = ((int)($bb->minX)) >> 4;
		$minChunkZ = ((int)($bb->minZ)) >> 4;
		$maxChunkX = ((int)($bb->maxX)) >> 4;
		$maxChunkZ = ((int)($bb->maxZ)) >> 4;
		$ents = [];
		//TODO also index by chunkY?
		for($chunkX = $minChunkX; $chunkX <= $maxChunkX; ++$chunkX){
			for($chunkZ = $minChunkZ; $chunkZ <= $maxChunkZ; ++$chunkZ){
				$ind = "$chunkX $chunkZ";
				foreach($this->entityListPositioned[$ind] ?? [] as $ind2 => $entid){
					if(isset($this->entityList[$entid]) && $this->entityList[$entid] instanceof Entity && $this->entityList[$entid]->boundingBox->intersectsWith($bb)){
						$ents[$entid] = $this->entityList[$entid];
					}else if(!isset($this->entityList[$entid])){
						ConsoleAPI::debug("Removing entity from level array at index $ind/$ind2: $entid");
						unset($this->entityListPositioned[$ind][$ind2]);
					}
				}
			}
		}
		return $ents;
	}
	
	public function addBlockToSendQueue($x, $y, $z, $id, $meta){
		$this->queuedBlockUpdates["$x $y $z"] = [$x, $y, $z, $id, $meta];
	}
	
	public function setBlock(Vector3 $pos, Block $block, $update = true, $tiles = false, $direct = false){
		if(!isset($this->level) or (($pos instanceof Position) and $pos->level !== $this) or $pos->x < 0 or $pos->y < 0 or $pos->z < 0){
			return false;
		}
		$ret = $this->level->setBlock($pos->x, $pos->y, $pos->z, $block->getID(), $block->getMetadata());
		if($ret === true){ 
			if(!($pos instanceof Position)){
				$pos = new Position($pos->x, $pos->y, $pos->z, $this);
			}
			$block->position($pos);
			if($direct === true){
				$this->addBlockToSendQueue($pos->x, $pos->y, $pos->z, $block->id, $block->meta);
			}else{
				$i = ($pos->x >> 4) . ":" . ($pos->y >> 4) . ":" . ($pos->z >> 4);
				if(!isset($this->changedBlocks[$i])){
					$this->changedBlocks[$i] = [];
					$this->changedCount[$i] = 0;
				}
				$this->changedBlocks[$i][] = [$block->x, $block->y, $block->z, $block->id, $block->getMetadata()];
				++$this->changedCount[$i];
			}

			if($update === true){
				$this->server->api->block->blockUpdateAround($pos, BLOCK_UPDATE_NORMAL, 1);
			}
			if($tiles === true){
				if(($t = $this->server->api->tile->get($pos)) !== false){
					$t->close();
				}
			}
		}
		return $ret;
	}

	public function getMiniChunk($X, $Z, $Y){
		if(!isset($this->level)){
			return false;
		}
		return $this->level->getMiniChunk($X, $Z, $Y);
	}

	public function setMiniChunk($X, $Z, $Y, $data){
		if(!isset($this->level)){
			return false;
		}
		$this->changedCount[$X . ":" . $Y . ":" . $Z] = 4096;
		return $this->level->setMiniChunk($X, $Z, $Y, $data);
	}

	public function loadChunk($X, $Z){
		if(!isset($this->level)){
			return false;
		}
		return $this->level->loadChunk($X, $Z);
	}

	public function unloadChunk($X, $Z, $force = false){
		if(!isset($this->level)){
			return false;
		}

		if($force !== true and $this->isSpawnChunk($X, $Z)){
			return false;
		}
		return $this->level->unloadChunk($X, $Z, $this->server->saveEnabled);
	}

	public function getOrderedChunk($X, $Z, $Yndex){
		if(!isset($this->level)){
			return false;
		}

		$raw = [];
		for($Y = 0; $Y < 8; ++$Y){
			if(($Yndex & (1 << $Y)) > 0){
				$raw[$Y] = $this->level->getMiniChunk($X, $Z, $Y);
			}
		}

		$ordered = "";
		$flag = chr($Yndex);
		for($j = 0; $j < 256; ++$j){
			$ordered .= $flag;
			foreach($raw as $mini){
				$ordered .= substr($mini, $j << 5, 24); //16 + 8
			}
		}
		return $ordered;
	}

	public function getOrderedMiniChunk($X, $Z, $Y){
		if(!isset($this->level)){
			return false;
		}
		$raw = $this->level->getMiniChunk($X, $Z, $Y);
		$ordered = "";
		$flag = chr(1 << $Y);
		for($j = 0; $j < 256; ++$j){
			$ordered .= $flag . substr($raw, $j << 5, 24); //16 + 8
		}
		return $ordered;
	}

	public function getSafeSpawn($spawn = false){
		if($spawn === false){
			$spawn = $this->getSpawn();
		}
		if($spawn instanceof Vector3){
			$x = (int) round($spawn->x);
			$y = (int) round($spawn->y);
			$z = (int) round($spawn->z);
			if($x < 0 || $x > 255 || $z < 0 || $z > 255){
				return new Position($x, 128, $z, $this);
			}
			for(; $y > 0; --$y){
				$v = new Vector3($x, $y, $z);
				$b = $this->getBlock($v->getSide(0));
				if($b === false){
					return $spawn;
				}elseif(!($b instanceof AirBlock)){
					break;
				}
			}
			for(; $y < 128; ++$y){
				$v = new Vector3($x, $y, $z);
				if($this->getBlock($v->getSide(1)) instanceof AirBlock){
					if($this->getBlock($v) instanceof AirBlock){
						return new Position($x, $y, $z, $this);
					}
				}else{
					++$y;
				}
			}
			return new Position($x, $y, $z, $this);
		}
		return false;
	}

	public function getSpawn(){
		if(!isset($this->level)){
			return false;
		}
		return new Position($this->level->getData("spawnX"), $this->level->getData("spawnY"), $this->level->getData("spawnZ"), $this);
	}
	
	/**
	 * @param number $x
	 * @param number $y
	 * @param number $z
	 * @param boolean $positionfy assign coordinates to block or not
	 * @return GenericBlock | false if failed
	 */
	
	public function getBlockWithoutVector($x, $y, $z, $positionfy = true){
		$b = $this->level->getBlock((int)$x, (int)$y, (int)$z);
		return BlockAPI::get($b[0], $b[1], $positionfy ? new Position($x, $y, $z, $this) : false);
	}
	
	/**
	 * Recommended to use {@link getBlockWithoutVector()} if you dont have the vector
	 * @param Vector3 $pos
	 * @return Block|false if failed
	 */
	public function getBlock(Vector3 $pos){
		if(!isset($this->level) or ($pos instanceof Position) and $pos->level !== $this){
			return false;
		}
		return $this->getBlockWithoutVector($pos->x, $pos->y, $pos->z);
	}

	public function setSpawn(Vector3 $pos){
		if(!isset($this->level)){
			return false;
		}
		$this->level->setData("spawnX", $pos->x);
		$this->level->setData("spawnY", $pos->y);
		$this->level->setData("spawnZ", $pos->z);
	}

	public function getTime(){
		return (int) ($this->time);
	}

	public function setTime($time){
		$this->startTime = $this->time = (int) $time;
		$this->startCheck = microtime(true);
		$this->checkTime();
	}

	public function checkTime(){
		if(!isset($this->level)){
			return false;
		}
		$now = microtime(true);
		if($this->stopTime == true){
			$time = $this->startTime;
		}else{
			$time = $this->startTime + ($now - $this->startCheck) * 20;
		}
		if($this->server->api->dhandle("time.change", ["level" => $this, "time" => $time]) !== false){ //send time to player every 5 ticks
			$this->time = $time;
			$pk = new SetTimePacket;
			$pk->time = (int) $this->time;
			$pk->started = $this->stopTime == false;
			$this->server->api->player->broadcastPacket($this->players, $pk);
		}else{
			$this->time -= 20 * 13;
		}
	}
	
	public function isTimeStopped(){
		return $this->stopTime;
	}
	
	public function stopTime(){
		$this->stopTime = true;
		$this->startCheck = 0;
		$this->checkTime();
	}

	public function startTime(){
		$this->stopTime = false;
		$this->startCheck = microtime(true);
		$this->checkTime();
	}

	public function getSeed(){
		if(!isset($this->level)){
			return false;
		}
		return (int) $this->level->getData("seed");
	}

	public function setSeed($seed){
		if(!isset($this->level)){
			return false;
		}
		$this->level->setData("seed", (int) $seed);
	}

	public function scheduleBlockUpdate(Position $pos, $delay, $type = BLOCK_UPDATE_SCHEDULED){
		if(!isset($this->level)){
			return false;
		}
		return $this->server->api->block->scheduleBlockUpdate($pos, $delay, $type);
	}
}
