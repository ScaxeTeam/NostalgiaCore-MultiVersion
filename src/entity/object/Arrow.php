<?php

class Arrow extends Projectile{
	const TYPE = OBJECT_ARROW;
	
	
	public $shooterEID = 0;
	public $shotByEntity;
	
	public $inWall = false;
	public $groundTicks = 0;
	
	public $xTile, $yTile, $zTile;
	public $inTile, $inData;
	public $shake;
	public $inGround;
	public $airTicks = 0;
	public $criticial = false;
	
	function __construct(Level $level, $eid, $class, $type = 0, $data = [], $shooter = false){
		parent::__construct($level, $eid, $class, $type, $data);
		$this->gravity = 0.05;
		$this->setSize(0.5, 0.5);
		$this->setName("Arrow");
		$this->shooterEID = $shooter;
		$this->shotByEntity = $shooter instanceof Entity;
		$this->airTicks = $this->groundTicks = 0;
		//$this->server->schedule(1210, array($this, "update")); //Despawn
	}
	
	public function handleUpdate(){
		$pk = new MoveEntityPacket_PosRot;
		$pk->eid = $this->eid;
		$pk->x = $this->x;
		$pk->y = $this->y;
		$pk->z = $this->z;
		$pk->yaw = $this->yaw;
		$pk->pitch = $this->pitch;
		$this->server->api->player->broadcastPacket($this->level->players, $pk);
	}
	
	public function shoot($d, $d1, $d2, $f, $f1){ //original name from 0.8.1 IDA decompilation, var names are taken from b1.7.3
		$f2 = sqrt($d * $d + $d1 * $d1 + $d2 * $d2);
		$d /= $f2;
		$d1 /= $f2;
		$d2 /= $f2;
		$d += $this->random->nextGaussian() * 0.0075 * $f1; //0.0074999998323619366 replaced with 0.0075
		$d1 += $this->random->nextGaussian() * 0.0075 * $f1;
		$d2 += $this->random->nextGaussian() * 0.0075 * $f1;
		$d *= $f;
		$d1 *= $f;
		$d2 *= $f;
		$this->speedX = $d;
		$this->speedY = $d1;
		$this->speedZ = $d2;
		$f3 = sqrt($d * $d + $d2 * $d2);
		$this->yaw = (atan2($d, $d2) * 180) / M_PI;
		$this->pitch = (atan2($d1, $f3) * 180) / M_PI;
		$this->updatePosition();
		//$this->update();
		//TODO i guess? $ticksInGround = 0;
	}
	public function sendMoveUpdate()
	{
		$pk = new MoveEntityPacket_PosRot();
		$pk->eid = $this->eid;
		$pk->x = $this->x;
		$pk->y = $this->y;
		$pk->z = $this->z;
		$pk->yaw = $this->yaw;
		$pk->pitch = $this->pitch;
		foreach($this->level->players as $p){ //sending packets directly makes movement less laggy
			$p->directDataPacket(clone $pk);
		}
	}
	public function update($now){
		$this->lastX = $this->x;
		$this->lastY = $this->y;
		$this->lastZ = $this->z;
		$this->lastPitch = $this->pitch;
		$this->lastYaw = $this->yaw;
		$this->needsUpdate = true;
		$this->handleWaterMovement(); //TODO: maybe just call parent::update(); ?
		if($this->fire > 0){
			if(!$this->isImmuneToFire){
				if(($this->fire % 20) == 0){
					$this->harm(1, "burning");
				}
				--$this->fire;
			}else{
				$this->fire -= 4;
				if($this->fire <= 0) $this->fire = 0;
			}
			
			if($this->fire <= 0){
				$this->updateMetadata();
			}
		}
		
		if($this->handleLavaMovement()){
			if(!$this->isImmuneToFire){
				$this->harm(4, "fire");
				$oldOnFire = $this->fire;
				if($oldOnFire < 20*30) $this->fire = 20*30; //30 seconds
			}
		}
		
		if($this->isInVoid()){
			$this->outOfWorld();
		}
		//Entity::update end
		
		
		if($this->lastPitch == $this->lastYaw && $this->lastYaw == 0){
			$v1 = sqrt($this->speedX*$this->speedX + $this->speedZ*$this->speedZ);
			$this->lastYaw = $this->yaw = atan2($this->speedX, $this->speedZ) * 180 / M_PI;
			$this->lastPitch = $this->pitch = atan2($this->speedY, $v1) * 180 / M_PI;
		}
		
		$v16 = $this->level->level->getBlockID($this->xTile, $this->yTile, $this->zTile);
		if($v16 > 0){
			//TODO this->inGround = true + checks
		}
		
		if($this->shake > 0){
			--$this->shake;
		}
		
		if($this->inGround){
			//TODO despawn after 1200 if if & meta == this->inTIle, this->inData
		}else{
			++$this->airTicks;
			$start = new Vector3($this->x, $this->y, $this->z);
			$end = new Vector3($this->x + $this->speedX, $this->y + $this->speedY, $this->z + $this->speedZ);
			/**
			 * @var MovingObjectPosition $v4
			 */
			$v4 = $this->level->rayTraceBlocks($start, $end);
			$start = new Vector3($this->x, $this->y, $this->z); //TODO remove?
			if($v4 != null){
				$end = new Vector3($v4->hitVector->x, $v4->hitVector->y, $v4->hitVector->z);
			}else{
				$end = new Vector3($this->x + $this->speedX, $this->y + $this->speedY, $this->z + $this->speedZ);
			}
			
			//TODO entity collisions
			console($v4);
			if($v4 != null){
				if($v4->entityHit != null){
					//TODO entity hit
				}else{
					$this->xTile = $v4->blockX;
					$this->yTile = $v4->blockY;
					$this->zTile = $v4->blockZ;
					
					[$this->inTile, $this->inData] = $this->level->level->getBlock($this->xTile, $this->yTile, $this->zTile);
					
					$this->speedX = $v4->hitVector->x - $this->x;
					$this->speedY = $v4->hitVector->y - $this->y;
					$this->speedZ = $v4->hitVector->z - $this->z;
					
					$v21 = sqrt($this->speedX*$this->speedX + $this->speedY*$this->speedY + $this->speedZ*$this->speedZ);
					$this->x -= $this->speedX / $v21 * 0.05;
					$this->y -= $this->speedY / $v21 * 0.05;
					$this->z -= $this->speedZ / $v21 * 0.05;
					
					$this->inGround = true;
					$this->shake = 7;
					$this->criticial = false;
				}
			}
			
			$this->x += $this->speedX;
			$this->y += $this->speedY;
			$this->z += $this->speedZ;
			$v21 = sqrt($this->speedX*$this->speedX + $this->speedZ*$this->speedZ);
			$this->yaw = atan2($this->speedX, $this->speedZ) * 180 / M_PI;
			$this->pitch = atan2($this->speedY, $v21) * 180 / M_PI;
			
			$this->pitch = $this->lastPitch + ($this->pitch - $this->lastPitch) * 0.2;
			$this->yaw = $this->lastYaw + ($this->yaw - $this->lastYaw) * 0.2;
			
			$v24 = $this->inWater ? 0.8 : 0.99;
			$this->speedX *= $v24;
			$this->speedY *= $v24;
			$this->speedZ *= $v24;
			$this->speedY -= 0.05;
			//$this->setP
			$v7 = $this->width / 2;
			$v8 = $this->height;
			$this->boundingBox->setBounds($this->x - $v7, $this->y - $this->yOffset /*+ $this->ySize*/, $this->z - $v7, $this->x + $v7, $this->y - $this->yOffset + $v8 /*+ $this->ySize*/, $this->z + $v7);
			
			$this->doBlocksCollision();
			console($this);
			
		}
		
		
		
	}
	
	public function spawn($player){
		if($this->type === OBJECT_ARROW){
			$pk = new AddEntityPacket;
			$pk->eid = $this->eid;
			$pk->type = $this->type;
			$pk->x = $this->x;
			$pk->y = $this->y;
			$pk->z = $this->z;
			$pk->did = 1;		
			$pk->speedX = $this->speedX;
			$pk->speedY = $this->speedY;
			$pk->speedZ = $this->speedZ;
			$player->dataPacket($pk);
		}
	}
}