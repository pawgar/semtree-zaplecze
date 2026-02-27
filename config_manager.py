import json
import os

CONFIG_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "config.json")


def load_sites():
    """Load sites list from config.json. Returns list of site dicts."""
    if not os.path.exists(CONFIG_FILE):
        return []
    with open(CONFIG_FILE, "r", encoding="utf-8") as f:
        data = json.load(f)
    return data.get("sites", [])


def save_sites(sites):
    """Save sites list to config.json."""
    with open(CONFIG_FILE, "w", encoding="utf-8") as f:
        json.dump({"sites": sites}, f, indent=2, ensure_ascii=False)


def add_site(name, url, username, app_password):
    """Add a new site. Returns updated sites list."""
    sites = load_sites()
    sites.append({
        "name": name,
        "url": url.rstrip("/"),
        "username": username,
        "app_password": app_password,
    })
    save_sites(sites)
    return sites


def update_site(index, name, url, username, app_password):
    """Update site at given index. Returns updated sites list."""
    sites = load_sites()
    sites[index] = {
        "name": name,
        "url": url.rstrip("/"),
        "username": username,
        "app_password": app_password,
    }
    save_sites(sites)
    return sites


def remove_site(index):
    """Remove site at given index. Returns updated sites list."""
    sites = load_sites()
    sites.pop(index)
    save_sites(sites)
    return sites
