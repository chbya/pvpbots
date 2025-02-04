<?php

namespace chbya\Bots;

use pocketmine\world\Position;

class Arena {
    private string $name;
    private ?Position $playerSpawn;
    private ?Position $botSpawn;
    private bool $isSetup = false;

    public function __construct(string $name) {
        $this->name = $name;
        $this->playerSpawn = null;
        $this->botSpawn = null;
    }

    public function getName(): string {
        return $this->name;
    }

    public function setPlayerSpawn(Position $pos): void {
        $this->playerSpawn = $pos;
        $this->checkSetup();
    }

    public function setBotSpawn(Position $pos): void {
        $this->botSpawn = $pos;
        $this->checkSetup();
    }

    public function getPlayerSpawn(): ?Position {
        return $this->playerSpawn;
    }

    public function getBotSpawn(): ?Position {
        return $this->botSpawn;
    }

    private function checkSetup(): void {
        $this->isSetup = ($this->playerSpawn !== null && $this->botSpawn !== null);
    }

    public function isSetup(): bool {
        return $this->isSetup;
    }

    public function serialize(): array {
        return [
            "name" => $this->name,
            "playerSpawn" => [
                "x" => $this->playerSpawn?->x,
                "y" => $this->playerSpawn?->y,
                "z" => $this->playerSpawn?->z,
                "world" => $this->playerSpawn?->getWorld()->getFolderName()
            ],
            "botSpawn" => [
                "x" => $this->botSpawn?->x,
                "y" => $this->botSpawn?->y,
                "z" => $this->botSpawn?->z,
                "world" => $this->botSpawn?->getWorld()->getFolderName()
            ]
        ];
    }

    public static function deserialize(array $data, Main $plugin): ?self {
        $arena = new self($data["name"]);
        
        if(isset($data["playerSpawn"]) && isset($data["botSpawn"])) {
            $playerWorld = $plugin->getServer()->getWorldManager()->getWorldByName($data["playerSpawn"]["world"]);
            $botWorld = $plugin->getServer()->getWorldManager()->getWorldByName($data["botSpawn"]["world"]);
            
            if($playerWorld !== null && $botWorld !== null) {
                $arena->setPlayerSpawn(new Position(
                    $data["playerSpawn"]["x"],
                    $data["playerSpawn"]["y"],
                    $data["playerSpawn"]["z"],
                    $playerWorld
                ));
                
                $arena->setBotSpawn(new Position(
                    $data["botSpawn"]["x"],
                    $data["botSpawn"]["y"],
                    $data["botSpawn"]["z"],
                    $botWorld
                ));
                
                return $arena;
            }
        }
        
        return null;
    }
}