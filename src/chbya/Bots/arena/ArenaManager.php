<?php

namespace chbya\Bots\arena;

use chbya\Bots\Main;
use pocketmine\player\Player;
use jojoe77777\FormAPI\SimpleForm;

class ArenaManager {

    private Main $plugin;

    /** @var PvPBotArena[] */
    private array $arenas = [];

    /** @var Player[] */
    private array $queue = []; // Player queue

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        
        // Register arenas for each mode (e.g., "sumo", "nodebuff", "gapple", "boxing")
        $this->arenas["sumo"] = new PvPBotArena("sumo", $plugin);
        $this->arenas["nodebuff"] = new PvPBotArena("nodebuff", $plugin);
        $this->arenas["gapple"] = new PvPBotArena("gapple", $plugin);
        $this->arenas["boxing"] = new PvPBotArena("boxing", $plugin);
    }

    /**
     * Add a player to the queue.
     */
    public function addToQueue(Player $player): void {
        if (!in_array($player, $this->queue, true)) {
            $this->queue[] = $player;
            $player->sendMessage("You have been added to the queue.");
        } else {
            $player->sendMessage("You are already in the queue.");
        }

        // Automatically start a match if the player is at the front of the queue
        if (count($this->queue) === 1) {
            $this->startNextMatch();
        }
    }

    /**
     * Remove a player from the queue.
     */
    public function removeFromQueue(Player $player): void {
        $key = array_search($player, $this->queue, true);
        if ($key !== false) {
            unset($this->queue[$key]);
            $this->queue = array_values($this->queue); // Re-index array
            $player->sendMessage("You have been removed from the queue.");
        } else {
            $player->sendMessage("You are not in the queue.");
        }
    }

    /**
     * Start the next match in the queue.
     */
    public function startNextMatch(): void {
        if (empty($this->queue)) {
            return;
        }

        $player = array_shift($this->queue); // Get the first player in the queue
        $this->queue = array_values($this->queue); // Re-index array

        // For this example, we'll use the 'sumo' arena
        $arena = $this->getArena("sumo");
        if ($arena !== null) {
            $arena->startMatch($player);
        } else {
            $player->sendMessage("Arena not found.");
        }

        // Start the next match automatically for the next player in the queue
        if (!empty($this->queue)) {
            $this->startNextMatch();
        }
    }

    /**
     * Send a queue selection form to the player using jojoe77777/FormAPI
     */
    public function sendQueueForm(Player $player): void {
        $form = new SimpleForm(function (Player $submitter, ?int $data) {
            if ($data === null) {
                // The player closed the form without making a selection
                return;
            }
            
            // Map form button indices to arena modes
            $modes = ["sumo", "nodebuff", "gapple", "boxing"];

            if (!isset($modes[$data])) {
                $submitter->sendMessage("Invalid selection.");
                return;
            }

            $mode = $modes[$data];
            $arena = $this->getArena($mode);
            if ($arena !== null) {
                $arena->startMatch($submitter);
            } else {
                $submitter->sendMessage("Arena not found for mode: $mode");
            }
        });

        $form->setTitle("PvPBot Queues");
        $form->setContent("Select a queue mode to fight the bot:");

        // Add form buttons for each mode
        $form->addButton("Sumo");
        $form->addButton("NoDebuff");
        $form->addButton("Gapple");
        $form->addButton("Boxing");

        // Send the form to the player
        $player->sendForm($form);
    }

    /**
     * Retrieve the arena by its mode
     */
    public function getArena(string $mode): ?PvPBotArena {
        return $this->arenas[$mode] ?? null;
    }
}