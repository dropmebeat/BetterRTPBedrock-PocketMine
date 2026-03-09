# BetterRTP

**BetterRTP** is a high-performance, customizable teleportation plugin for **PocketMine-MP**. It allows players to teleport to a random location within a defined border, making it perfect for Survival, Factions, or Anarchy servers.

## Key Features

*   **Smart Teleportation:** Automatically finds safe landing spots (avoids water, lava, and suffocating blocks).
*   **Custom Borders:** Define `min` and `max` X/Z coordinates for the teleportation range.
*   **World Support:** Enable or disable random teleportation in specific worlds.
*   **Cooldown & Delay:** Prevent spamming with configurable cooldown timers and teleportation delays.
*   **Economy Integration:** (Optional) Charge players money for each random teleport.
*   **Permissions:** Granular control over who can use the command and bypass cooldowns.

## Commands


| Command | Description | Permission |
|---------|-------------|------------|
| `/rtp` | Teleport to a random location | `betterrtp.use` |
| `/rtp help` | Show help menu | `betterrtp.use` |
| `/rtp reload` | Reload the configuration | `betterrtp.admin` |

## Configuration Preview

```yaml
# BetterRTP Settings
range:
  max: 2000
  min: -2000

# Time in seconds
cooldown: 60
delay: 3

# Avoid these blocks
avoid_blocks:
  - "minecraft:lava"
  - "minecraft:water"
  - "minecraft:still_lava"
  - "minecraft:still_water"
