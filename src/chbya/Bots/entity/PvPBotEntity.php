<?php

namespace chbya\Bots\entity;

use pocketmine\entity\Human;
use pocketmine\entity\Location;
use pocketmine\entity\Skin;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\EntityAttackSound;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\network\mcpe\protocol\AnimatePacket;
use pocketmine\entity\Living;

class PvPBotEntity extends Human {
    
    public const NETWORK_ID = 64;

    protected float $moveSpeed = 0.35;
    protected float $knockbackMultiplier = 0.4;
    protected float $verticalKnockback = 0.5;
    protected float $damageAmount = 3.0;
    protected float $attackRange = 3.5;
    protected float $visualAttackRange = 6.0;

    protected int $pathUpdateInterval = 5;
    protected float $engageRange = 30.0;
    protected int $attackCooldownTicks = 10;
    protected int $lastAttackTick = 0;
    protected int $updatePathTick = 0;
    protected bool $isAttacking = false;
    protected ?Player $targetPlayer = null;
    protected float $drag = 0.02;
    protected int $hurtTime = 0;
    protected int $maxHurtTime = 10;
    protected float $jumpVelocity = 0.52;
    protected float $baseGravity = 0.028;
    protected float $gravityDrag = 0.99;
    protected int $jumpTicks = 0;
    protected int $jumpDelay = 10;
    protected float $maxFallSpeed = 0.4;
    protected bool $useVanillaKnockback = false;

    public function __construct(Location $location, Skin $skin) {
        parent::__construct($location, $skin);
        $this->setNameTagAlwaysVisible(true);
        $this->setNameTag("§l§cPvP Bot");
        $this->setCanSaveWithChunk(false);
    }

    public function setBotConfig(array $config): void {
        // Basic settings
        $this->moveSpeed = $config["move_speed"] ?? 0.35;
        $this->knockbackMultiplier = $config["knockback"] ?? 0.4;
        $this->verticalKnockback = $config["vertical_knockback"] ?? 0.5;
        $this->damageAmount = $config["damage"] ?? 3.0;
        $this->attackRange = $config["reach"] ?? 3.5;
        $this->useVanillaKnockback = $config["use_vanilla_knockback"] ?? false;

        // Movement settings
        $this->drag = $config["drag"] ?? 0.02;
        $this->jumpVelocity = $config["jump_velocity"] ?? 0.52;
        $this->jumpDelay = $config["jump_delay"] ?? 10;
        $this->baseGravity = $config["base_gravity"] ?? 0.028;
        $this->gravityDrag = $config["gravity_drag"] ?? 0.99;
        $this->maxFallSpeed = $config["max_fall_speed"] ?? 0.4;

        // Combat settings
        $this->attackRange = $config["attack_range"] ?? 3.5;
        $this->visualAttackRange = $config["visual_attack_range"] ?? 6.0;
        $this->engageRange = $config["engage_range"] ?? 30.0;
        $this->attackCooldownTicks = $config["attack_cooldown_ticks"] ?? 10;
        $this->pathUpdateInterval = $config["path_update_interval"] ?? 5;
        $this->maxHurtTime = $config["max_hurt_time"] ?? 10;
    }

    protected function initEntity(CompoundTag $nbt): void {
        parent::initEntity($nbt);
        $this->setMaxHealth(20);
        $this->setHealth(20);
        $this->setScale(1.0);
    }

    public function attack(EntityDamageEvent $source): void {
        if($this->noDamageTicks > 0) {
            $source->cancel();
            return;
        }

        parent::attack($source);
        
        if($source instanceof EntityDamageByEntityEvent) {
            $attacker = $source->getDamager();
            if($attacker instanceof Player) {
                if(!$source->isCancelled()) {
                    $this->hurtTime = $this->maxHurtTime;
                    
                    if(!$this->useVanillaKnockback) {
                        $xDiff = $this->getLocation()->x - $attacker->getLocation()->x;
                        $zDiff = $this->getLocation()->z - $attacker->getLocation()->z;
                        
                        $f = sqrt($xDiff * $xDiff + $zDiff * $zDiff);
                        if($f <= 0) {
                            return;
                        }

                        $f = 1.0 / $f;

                        $baseMotion = new Vector3(
                            $xDiff * $f * $this->knockbackMultiplier,
                            0.0,
                            $zDiff * $f * $this->knockbackMultiplier
                        );

                        $baseMotion->y = $this->verticalKnockback;

                        $currentMotion = $this->getMotion();
                        $newMotion = new Vector3(
                            $baseMotion->x + $currentMotion->x * 0.2,
                            $baseMotion->y,
                            $baseMotion->z + $currentMotion->z * 0.2
                        );
                        
                        $this->setMotion($newMotion);
                    }
                    $this->noDamageTicks = 10;
                }
            }
        }
        
        if($this->getHealth() <= 0) {
            $this->onBotDeath($source);
        }
    }

    private function onBotDeath(EntityDamageEvent $source): void {
        if($source instanceof EntityDamageByEntityEvent) {
            $killer = $source->getDamager();
            if($killer instanceof Player && $killer->isOnline()) {
                $messages = $this->getOwningPlugin()->getMessages();
                $victory = $messages['victory'] ?? ['title' => '§l§aVICTORY!', 'subtitle' => '§7You defeated the bot!'];
                
                $killer->sendTitle(
                    $victory['title'],
                    $victory['subtitle'],
                    20,
                    40,
                    20
                );
                
                $this->getOwningPlugin()->removeFromArena($killer->getName());
            }
        }
        $this->close();
    }

    public function onUpdate(int $currentTick): bool {
        if (!$this->isAlive()) {
            return false;
        }

        if ($this->targetPlayer !== null) {
            if (!$this->targetPlayer->isOnline() || !$this->targetPlayer->isAlive()) {
                if ($this->targetPlayer->isOnline()) {
                    $messages = $this->getOwningPlugin()->getMessages();
                    $defeat = $messages['defeat'] ?? ['title' => '§l§cLOSER', 'subtitle' => '§7Better luck next time!'];
                    
                    $this->targetPlayer->sendTitle(
                        $defeat['title'],
                        $defeat['subtitle'],
                        20,
                        40,
                        20
                    );
                }
                
                if ($this->targetPlayer !== null) {
                    $this->getOwningPlugin()->removeFromArena($this->targetPlayer->getName());
                }
                
                $this->close();
                return false;
            }
        }

        $this->updatePathTick++;
        $this->lastAttackTick++;

        if($this->jumpTicks > 0) {
            $this->jumpTicks--;
        }

        if($this->hurtTime > 0) {
            $this->hurtTime--;
        }

        $motion = $this->getMotion();
        
        if(!$this->isOnGround()) {
            $motion->y -= $this->baseGravity;
            $motion->y *= $this->gravityDrag;
            
            if($motion->y < -$this->maxFallSpeed) {
                $motion->y = -$this->maxFallSpeed;
            }
        }
        
        if($motion->lengthSquared() > 0) {
            $motion->x *= (1 - $this->drag);
            $motion->z *= (1 - $this->drag);
        }
        
        $this->setMotion($motion);

        if ($this->updatePathTick >= $this->pathUpdateInterval) {
            $this->updatePathTick = 0;
            $this->updateAI();
        }

        return parent::onUpdate($currentTick);
    }

    private function updateAI(): void {
        $target = $this->findNearestPlayer($this->engageRange);

        if ($target === null) {
            $this->isAttacking = false;
            if ($this->targetPlayer !== null) {
                $this->close();
            }
            return;
        }

        $this->targetPlayer = $target;
        $this->updateHeadRotation($target);
        $distance = $this->getLocation()->distance($target->getLocation());

        if ($distance <= $this->engageRange) {
            if ($distance <= $this->attackRange) {
                $this->attemptAttack($target, true);
            } else if ($distance <= $this->visualAttackRange) {
                if ($this->lastAttackTick >= $this->attackCooldownTicks * 0.75) {
                    $this->attemptAttack($target, false);
                }
            }
            $this->moveTowardsTarget($target);
        } else {
            $this->isAttacking = false;
            $this->moveTowardsTarget($target);
        }
    }

    private function findNearestPlayer(float $maxDistance): ?Player {
        if ($this->targetPlayer !== null) {
            if ($this->targetPlayer->isOnline() && $this->targetPlayer->isAlive() &&
                $this->getLocation()->distance($this->targetPlayer->getLocation()) <= $maxDistance) {
                return $this->targetPlayer;
            }
        }

        $nearestPlayer = null;
        $nearestDistanceSquared = $maxDistance * $maxDistance;

        foreach ($this->getWorld()->getPlayers() as $player) {
            if (!$player->isOnline() || !$player->isAlive() || $player->isClosed()) {
                continue;
            }

            $distanceSquared = $this->getLocation()->distanceSquared($player->getLocation());
            if ($distanceSquared < $nearestDistanceSquared) {
                $nearestDistanceSquared = $distanceSquared;
                $nearestPlayer = $player;
            }
        }

        return $nearestPlayer;
    }

    private function updateHeadRotation(Player $target): void {
        $dx = $target->getLocation()->x - $this->getLocation()->x;
        $dz = $target->getLocation()->z - $this->getLocation()->z;
        $dy = ($target->getLocation()->y + $target->getEyeHeight()) - 
              ($this->getLocation()->y + $this->getEyeHeight());

        $yaw = -atan2($dx, $dz) * 180 / M_PI;
        $distance = sqrt($dx * $dx + $dz * $dz);
        $pitch = -atan2($dy, $distance) * 180 / M_PI;

        $this->setRotation($yaw, $pitch);
    }

    private function attemptAttack(Player $target, bool $dealDamage = true): void {
        if ($this->lastAttackTick >= ($dealDamage ? $this->attackCooldownTicks : $this->attackCooldownTicks * 0.5)) {
            $this->lastAttackTick = 0;
            $this->isAttacking = true;

            $pk = new AnimatePacket();
            $pk->actorRuntimeId = $this->getId();
            $pk->action = AnimatePacket::ACTION_SWING_ARM;
            
            foreach($this->getViewers() as $player) {
                $player->getNetworkSession()->sendDataPacket($pk);
            }

            $this->getWorld()->addSound($this->getLocation(), new EntityAttackSound());

            if ($dealDamage) {
                $ev = new EntityDamageByEntityEvent(
                    $this,
                    $target,
                    EntityDamageEvent::CAUSE_ENTITY_ATTACK,
                    $this->damageAmount,
                    [],
                    $this->knockbackMultiplier
                );
                
                $target->attack($ev);
            }
        }
    }

    private function moveTowardsTarget(Player $target): void {
        if($this->hurtTime > 0) {
            return;
        }

        $targetPos = $target->getLocation();
        $currentPos = $this->getLocation();

        $dx = $targetPos->x - $currentPos->x;
        $dz = $targetPos->z - $currentPos->z;

        $length = sqrt($dx * $dx + $dz * $dz);
        if ($length > 0) {
            $dx /= $length;
            $dz /= $length;
        }

        if($this->isOnGround()) {
            $moveX = $dx * $this->moveSpeed;
            $moveZ = $dz * $this->moveSpeed;

            $direction = $this->getDirectionVector();
            $frontBlock = $this->getWorld()->getBlock($this->getPosition()->add($direction->x, 0, $direction->z));
            $upBlock = $this->getWorld()->getBlock($frontBlock->getPosition()->add(0, 1, 0));
            
            if(!$frontBlock->isTransparent() && $upBlock->isTransparent() && $this->jumpTicks <= 0) {
                $this->jump();
                $this->jumpTicks = $this->jumpDelay;
            }

            $this->setMotion(new Vector3($moveX, $this->getMotion()->y, $moveZ));
        }
    }

    public function jump(): void {
        if($this->isOnGround()) {
            $this->setMotion($this->getMotion()->add(0, $this->jumpVelocity, 0));
        }
    }

    private function getOwningPlugin(): \chbya\Bots\Main {
        return \pocketmine\Server::getInstance()->getPluginManager()->getPlugin("Bots");
    }

    public function getName(): string {
        return "PvPBot";
    }

    public function getMaxHealth(): int {
        return 20;
    }

    protected function getInitialSizeInfo(): EntitySizeInfo {
        return new EntitySizeInfo(1.8, 0.6, 1.62);
    }

    public static function getNetworkTypeId(): string {
        return "minecraft:player";
    }
}