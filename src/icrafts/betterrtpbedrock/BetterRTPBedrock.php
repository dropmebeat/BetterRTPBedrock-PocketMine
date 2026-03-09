<?php

declare(strict_types=1);

namespace icrafts\betterrtpbedrock;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use pocketmine\world\World;

final class BetterRTPBedrock extends PluginBase implements Listener
{
    /** @var array<string, int> */
    private array $cooldowns = [];

    /** @var array<string, array{task: TaskHandler, x: float, y: float, z: float, world: string}> */
    private array $pendingTeleports = [];

    public function onEnable(): void
    {
        $this->saveDefaultConfig();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onJoin(PlayerJoinEvent $event): void
    {
        $player = $event->getPlayer();
        if ($player->hasPlayedBefore()) {
            return;
        }

        if (
            !$this->getConfig()->getNested(
                "Settings.RtpOnFirstJoin.Enabled",
                false,
            )
        ) {
            return;
        }

        $worldName = (string) $this->getConfig()->getNested(
            "Settings.RtpOnFirstJoin.World",
            $player->getWorld()->getFolderName(),
        );
        $targetWorld =
            $this->getLoadedWorldByName($worldName) ?? $player->getWorld();
        $this->startRtp($player, $player, $targetWorld);
    }

    public function onMove(PlayerMoveEvent $event): void
    {
        $player = $event->getPlayer();
        $key = strtolower($player->getName());
        if (!isset($this->pendingTeleports[$key])) {
            return;
        }

        if (
            !$this->getConfig()->getNested("Settings.Delay.CancelOnMove", true)
        ) {
            return;
        }

        $from = $event->getFrom();
        $to = $event->getTo();
        if ($from->x === $to->x && $from->y === $to->y && $from->z === $to->z) {
            return;
        }

        $this->pendingTeleports[$key]["task"]->cancel();
        unset($this->pendingTeleports[$key]);
        $this->msg($player, "Messages.DelayCancelledMove");
    }

    public function onCommand(
        CommandSender $sender,
        Command $command,
        string $label,
        array $args,
    ): bool {
        if (strtolower($command->getName()) !== "betterrtp") {
            return false;
        }

        if (count($args) === 0) {
            return $this->cmdSelfRtp($sender);
        }

        $sub = strtolower((string) $args[0]);
        switch ($sub) {
            case "help":
            case "?":
                $this->sendHelp($sender, $label);
                return true;

            case "reload":
                if (!$sender->hasPermission("betterrtp.reload")) {
                    $this->msg($sender, "Messages.NoPermission");
                    return true;
                }
                $this->reloadConfig();
                $this->msg($sender, "Messages.Reloaded");
                return true;

            case "version":
                if (!$sender->hasPermission("betterrtp.version")) {
                    $this->msg($sender, "Messages.NoPermission");
                    return true;
                }
                $this->msg($sender, "Messages.Version", [
                    "%version%" => $this->getDescription()->getVersion(),
                ]);
                return true;

            case "info":
                if (!$sender->hasPermission("betterrtp.info")) {
                    $this->msg($sender, "Messages.NoPermission");
                    return true;
                }
                return $this->cmdInfo($sender, $args);

            case "world":
                if (!$sender->hasPermission("betterrtp.world")) {
                    $this->msg($sender, "Messages.NoPermission");
                    return true;
                }
                return $this->cmdWorldRtp($sender, $args);

            case "player":
                if (!$sender->hasPermission("betterrtp.player")) {
                    $this->msg($sender, "Messages.NoPermission");
                    return true;
                }
                return $this->cmdPlayerRtp($sender, $args);

            default:
                return $this->cmdSelfRtp($sender);
        }
    }

    private function cmdSelfRtp(CommandSender $sender): bool
    {
        if (!$sender instanceof Player) {
            $this->msg($sender, "Messages.MustBePlayer");
            return true;
        }
        if (!$sender->hasPermission("betterrtp.use")) {
            $this->msg($sender, "Messages.NoPermission");
            return true;
        }
        return $this->startRtp($sender, $sender, $sender->getWorld());
    }

    /**
     * /rtp world <world>
     */
    private function cmdWorldRtp(CommandSender $sender, array $args): bool
    {
        if (!$sender instanceof Player) {
            $this->msg($sender, "Messages.MustBePlayer");
            return true;
        }
        if (count($args) < 2) {
            $sender->sendMessage(
                TextFormat::colorize("&eUsage: /rtp world <world>"),
            );
            return true;
        }

        $targetWorld = $this->getLoadedWorldByName((string) $args[1]);
        if (!$targetWorld instanceof World) {
            $this->msg($sender, "Messages.WorldNotFound", [
                "%world%" => (string) $args[1],
            ]);
            return true;
        }

        if (
            !$sender->hasPermission("betterrtp.world.*") &&
            !$sender->hasPermission(
                "betterrtp.world." . strtolower($targetWorld->getFolderName()),
            )
        ) {
            $this->msg($sender, "Messages.NoPermission");
            return true;
        }

        return $this->startRtp($sender, $sender, $targetWorld);
    }

    /**
     * /rtp player <player> [world]
     */
    private function cmdPlayerRtp(CommandSender $sender, array $args): bool
    {
        if (count($args) < 2) {
            $sender->sendMessage(
                TextFormat::colorize("&eUsage: /rtp player <player> [world]"),
            );
            return true;
        }

        $target = $this->getServer()->getPlayerExact((string) $args[1]);
        if (!$target instanceof Player) {
            $this->msg($sender, "Messages.PlayerNotFound", [
                "%player%" => (string) $args[1],
            ]);
            return true;
        }

        $targetWorld = $target->getWorld();
        if (isset($args[2])) {
            $customWorld = $this->getLoadedWorldByName((string) $args[2]);
            if (!$customWorld instanceof World) {
                $this->msg($sender, "Messages.WorldNotFound", [
                    "%world%" => (string) $args[2],
                ]);
                return true;
            }
            $targetWorld = $customWorld;
        }

        return $this->startRtp($target, $sender, $targetWorld, true);
    }

    private function cmdInfo(CommandSender $sender, array $args): bool
    {
        $worldName = isset($args[1])
            ? (string) $args[1]
            : ($sender instanceof Player
                ? $sender->getWorld()->getFolderName()
                : "world");
        $world = $this->getLoadedWorldByName($worldName);
        if (!$world instanceof World) {
            $this->msg($sender, "Messages.WorldNotFound", [
                "%world%" => $worldName,
            ]);
            return true;
        }

        $settings = $this->getWorldSettings($world->getFolderName());
        $lines = $this->getConfig()->getNested("Messages.Info", []);
        if (!is_array($lines)) {
            return true;
        }

        foreach ($lines as $line) {
            $sender->sendMessage(
                TextFormat::colorize(
                    $this->replace((string) $line, [
                        "%world%" => $world->getFolderName(),
                        "%shape%" => (string) ($settings["Shape"] ?? "square"),
                        "%center_x%" => (string) ($settings["CenterX"] ?? 0),
                        "%center_z%" => (string) ($settings["CenterZ"] ?? 0),
                        "%min_radius%" =>
                            (string) ($settings["MinRadius"] ?? 10),
                        "%max_radius%" =>
                            (string) ($settings["MaxRadius"] ?? 1000),
                        "%min_y%" =>
                            (string) ($settings["MinY"] ?? $world->getMinY()),
                        "%max_y%" =>
                            (string) ($settings["MaxY"] ?? $world->getMaxY()),
                    ]),
                ),
            );
        }

        return true;
    }

    private function startRtp(
        Player $target,
        CommandSender $requester,
        World $requestedWorld,
        bool $forced = false,
    ): bool {
        $sourceWorld = $requestedWorld->getFolderName();
        $actualWorld = $this->resolveOverride($sourceWorld);

        if ($this->isWorldDisabled($actualWorld->getFolderName())) {
            $this->msg($requester, "Messages.WorldDisabled");
            return true;
        }

        if (
            !$forced &&
            !$target->hasPermission("betterrtp.bypass.cooldown") &&
            $this->getConfig()->getNested("Settings.Cooldown.Enabled", true)
        ) {
            $left = $this->cooldownLeft($target, $actualWorld->getFolderName());
            if ($left > 0) {
                $this->msg($requester, "Messages.Cooldown", [
                    "%seconds%" => (string) $left,
                ]);
                return true;
            }
        }

        $this->msg($requester, "Messages.Teleporting", [
            "%world%" => $actualWorld->getFolderName(),
        ]);
        $delaySeconds = (int) $this->getConfig()->getNested(
            "Settings.Delay.Time",
            0,
        );
        $useDelay =
            $this->getConfig()->getNested("Settings.Delay.Enabled", true) &&
            $delaySeconds > 0 &&
            !$target->hasPermission("betterrtp.bypass.delay") &&
            !$forced;

        if ($useDelay) {
            $this->msg($target, "Messages.DelayStarted", [
                "%seconds%" => (string) $delaySeconds,
            ]);
            $key = strtolower($target->getName());
            if (isset($this->pendingTeleports[$key])) {
                $this->pendingTeleports[$key]["task"]->cancel();
            }

            $pos = $target->getPosition();
            $task = $this->getScheduler()->scheduleDelayedTask(
                new ClosureTask(function () use (
                    $target,
                    $requester,
                    $actualWorld,
                    $forced,
                ): void {
                    $this->executeTeleport(
                        $target,
                        $requester,
                        $actualWorld,
                        $forced,
                    );
                }),
                $delaySeconds * 20,
            );

            $this->pendingTeleports[$key] = [
                "task" => $task,
                "x" => $pos->x,
                "y" => $pos->y,
                "z" => $pos->z,
                "world" => $pos->getWorld()->getFolderName(),
            ];
            return true;
        }

        $this->executeTeleport($target, $requester, $actualWorld, $forced);
        return true;
    }

    private function executeTeleport(
        Player $target,
        CommandSender $requester,
        World $world,
        bool $forced,
    ): void {
        $key = strtolower($target->getName());
        unset($this->pendingTeleports[$key]);

        if (!$target->isOnline()) {
            return;
        }

        $location = $this->findSafeRandomPosition($world);
        if (!$location instanceof Position) {
            $this->msg($requester, "Messages.NoSafeLocation", [
                "%attempts%" => (string) $this->getConfig()->getNested(
                    "Settings.MaxAttempts",
                    32,
                ),
            ]);
            return;
        }

        $target->teleport($location);
        if (
            !$forced &&
            $this->getConfig()->getNested("Settings.Cooldown.Enabled", true)
        ) {
            $this->applyCooldown($target, $world->getFolderName());
        }

        $this->msg($target, "Messages.Teleported", [
            "%x%" => (string) round($location->x, 1),
            "%y%" => (string) round($location->y, 1),
            "%z%" => (string) round($location->z, 1),
            "%world%" => $world->getFolderName(),
        ]);

        if ($requester !== $target) {
            $this->msg($requester, "Messages.Teleported", [
                "%x%" => (string) round($location->x, 1),
                "%y%" => (string) round($location->y, 1),
                "%z%" => (string) round($location->z, 1),
                "%world%" => $world->getFolderName(),
            ]);
        }
    }

    private function resolveOverride(string $worldName): World
    {
        $overrides = $this->getConfig()->get("Overrides", []);
        if (is_array($overrides) && isset($overrides[$worldName])) {
            $overrideWorld = $this->getLoadedWorldByName(
                (string) $overrides[$worldName],
            );
            if ($overrideWorld instanceof World) {
                return $overrideWorld;
            }
        }
        return $this->getLoadedWorldByName($worldName) ??
            $this->getServer()->getWorldManager()->getDefaultWorld();
    }

    private function isWorldDisabled(string $worldName): bool
    {
        $disabled = $this->getConfig()->get("DisabledWorlds", []);
        if (!is_array($disabled)) {
            return false;
        }
        foreach ($disabled as $entry) {
            if (strtolower((string) $entry) === strtolower($worldName)) {
                return true;
            }
        }
        return false;
    }

    private function cooldownLeft(Player $player, string $worldName): int
    {
        $cooldownKey = $this->cooldownKey($player, $worldName);
        $until = $this->cooldowns[$cooldownKey] ?? 0;
        if ($until <= time()) {
            return 0;
        }
        return $until - time();
    }

    private function applyCooldown(Player $player, string $worldName): void
    {
        $seconds = $this->worldCooldownSeconds($worldName);
        if ($seconds <= 0) {
            return;
        }
        $this->cooldowns[$this->cooldownKey($player, $worldName)] =
            time() + $seconds;
    }

    private function worldCooldownSeconds(string $worldName): int
    {
        $worldSettings = $this->getWorldSettings($worldName);
        if (isset($worldSettings["Cooldown"])) {
            return max(0, (int) $worldSettings["Cooldown"]);
        }
        return max(
            0,
            (int) $this->getConfig()->getNested("Settings.Cooldown.Time", 0),
        );
    }

    private function cooldownKey(Player $player, string $worldName): string
    {
        $perWorld = $this->getConfig()->getNested(
            "Settings.Cooldown.PerWorld",
            false,
        );
        if ($perWorld) {
            return strtolower($player->getName()) .
                ":" .
                strtolower($worldName);
        }
        return strtolower($player->getName()) . ":*";
    }

    private function findSafeRandomPosition(World $world): ?Position
    {
        $settings = $this->getWorldSettings($world->getFolderName());
        $maxAttempts = max(
            1,
            (int) $this->getConfig()->getNested("Settings.MaxAttempts", 32),
        );
        $shape = strtolower((string) ($settings["Shape"] ?? "square"));
        $minRadius = max(0, (int) ($settings["MinRadius"] ?? 10));
        $maxRadius = max(
            $minRadius + 1,
            (int) ($settings["MaxRadius"] ?? 1000),
        );
        $centerX = (int) ($settings["CenterX"] ?? 0);
        $centerZ = (int) ($settings["CenterZ"] ?? 0);
        $minY = max(
            $world->getMinY(),
            (int) ($settings["MinY"] ?? $world->getMinY()),
        );
        $maxY = min(
            $world->getMaxY() - 2,
            (int) ($settings["MaxY"] ?? $world->getMaxY() - 2),
        );

        for ($i = 0; $i < $maxAttempts; $i++) {
            [$x, $z] = $this->randomXZ(
                $shape,
                $centerX,
                $centerZ,
                $minRadius,
                $maxRadius,
            );
            $y = $this->findSafeY($world, $x, $z, $minY, $maxY);
            if ($y === null) {
                continue;
            }
            return new Position($x + 0.5, $y, $z + 0.5, $world);
        }

        return null;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function randomXZ(
        string $shape,
        int $centerX,
        int $centerZ,
        int $minRadius,
        int $maxRadius,
    ): array {
        if ($shape === "circle") {
            $angle = lcg_value() * 2 * M_PI;
            $radius = sqrt(
                lcg_value() *
                    ($maxRadius * $maxRadius - $minRadius * $minRadius) +
                    $minRadius * $minRadius,
            );
            $x = (int) round($centerX + $radius * cos($angle));
            $z = (int) round($centerZ + $radius * sin($angle));
            return [$x, $z];
        }

        $quadrant = mt_rand(0, 3);
        $range = max(1, $maxRadius - $minRadius);
        $dx = mt_rand($minRadius, $minRadius + $range);
        $dz = mt_rand($minRadius, $minRadius + $range);

        switch ($quadrant) {
            case 0:
                return [$centerX + $dx, $centerZ + $dz];
            case 1:
                return [$centerX - $dx, $centerZ - $dz];
            case 2:
                return [$centerX - $dx, $centerZ + $dz];
            default:
                return [$centerX + $dx, $centerZ - $dz];
        }
    }

    private function findSafeY(
        World $world,
        int $x,
        int $z,
        int $minY,
        int $maxY,
    ): ?float {
        for ($y = $maxY; $y >= $minY; $y--) {
            $ground = $world->getBlockAt($x, $y, $z);
            if (!$ground->isSolid()) {
                continue;
            }
            if ($this->isBlacklistedBlock($ground->getName())) {
                continue;
            }

            $feet = $world->getBlockAt($x, $y + 1, $z);
            $head = $world->getBlockAt($x, $y + 2, $z);
            if ($feet->isSolid() || $head->isSolid()) {
                continue;
            }

            return $y + 1.0;
        }
        return null;
    }

    private function isBlacklistedBlock(string $name): bool
    {
        $blacklist = $this->getConfig()->get("BlacklistedBlocks", []);
        if (!is_array($blacklist)) {
            return false;
        }

        $normalizedName = $this->normalizeBlockName($name);
        foreach ($blacklist as $entry) {
            if (
                $normalizedName === $this->normalizeBlockName((string) $entry)
            ) {
                return true;
            }
        }
        return false;
    }

    private function normalizeBlockName(string $name): string
    {
        $value = strtolower($name);
        $value = str_replace(["minecraft:", " "], ["", "_"], $value);
        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function getWorldSettings(string $worldName): array
    {
        $default = $this->getConfig()->get("Default", []);
        $customWorlds = $this->getConfig()->get("CustomWorlds", []);
        if (
            is_array($customWorlds) &&
            isset($customWorlds[$worldName]) &&
            is_array($customWorlds[$worldName])
        ) {
            return array_merge(
                is_array($default) ? $default : [],
                $customWorlds[$worldName],
            );
        }
        return is_array($default) ? $default : [];
    }

    private function getLoadedWorldByName(string $name): ?World
    {
        $name = trim($name);
        $world = $this->getServer()->getWorldManager()->getWorldByName($name);
        if ($world instanceof World) {
            return $world;
        }
        $withSpaces = str_replace("_", " ", $name);
        $world = $this->getServer()
            ->getWorldManager()
            ->getWorldByName($withSpaces);
        if ($world instanceof World) {
            return $world;
        }
        return null;
    }

    private function sendHelp(CommandSender $sender, string $label): void
    {
        $sender->sendMessage(
            TextFormat::colorize(
                "&e/" . $label . " &7- random teleport in current world",
            ),
        );
        $sender->sendMessage(
            TextFormat::colorize("&e/" . $label . " world <world>"),
        );
        $sender->sendMessage(
            TextFormat::colorize("&e/" . $label . " player <player> [world]"),
        );
        $sender->sendMessage(
            TextFormat::colorize("&e/" . $label . " info [world]"),
        );
        $sender->sendMessage(TextFormat::colorize("&e/" . $label . " reload"));
        $sender->sendMessage(TextFormat::colorize("&e/" . $label . " version"));
    }

    /**
     * @param array<string, string> $replace
     */
    private function msg(
        CommandSender $sender,
        string $path,
        array $replace = [],
    ): void {
        $text = (string) $this->getConfig()->getNested($path, "");
        if ($text === "") {
            return;
        }

        $prefix = (string) $this->getConfig()->getNested("Messages.Prefix", "");
        $full = $prefix . $this->replace($text, $replace);
        $sender->sendMessage(TextFormat::colorize($full));
    }

    /**
     * @param array<string, string> $replace
     */
    private function replace(string $text, array $replace): string
    {
        foreach ($replace as $k => $v) {
            $text = str_replace($k, $v, $text);
        }
        return $text;
    }
}
