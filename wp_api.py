import requests
import base64


class WordPressAPI:
    """WordPress REST API wrapper for password management."""

    TIMEOUT = 30

    def __init__(self, site_url, username, app_password):
        self.site_url = site_url.rstrip("/")
        self.api_base = f"{self.site_url}/wp-json/wp/v2"
        self.headers = self._build_auth_header(username, app_password)

    def _build_auth_header(self, username, app_password):
        token = base64.b64encode(
            f"{username}:{app_password}".encode()
        ).decode()
        return {
            "Authorization": f"Basic {token}",
            "Content-Type": "application/json",
        }

    def test_connection(self):
        """Test connection and return user info dict or raise exception."""
        resp = requests.get(
            f"{self.api_base}/users/me",
            headers=self.headers,
            timeout=self.TIMEOUT,
        )
        resp.raise_for_status()
        data = resp.json()
        return {
            "id": data.get("id"),
            "name": data.get("name"),
            "slug": data.get("slug"),
            "roles": data.get("roles", []),
        }

    def change_password(self, new_password):
        """Change the authenticated user's login password.

        Returns user info dict on success or raises exception.
        Application Passwords remain unaffected.
        """
        resp = requests.post(
            f"{self.api_base}/users/me",
            headers=self.headers,
            json={"password": new_password},
            timeout=self.TIMEOUT,
        )
        resp.raise_for_status()
        data = resp.json()
        return {
            "id": data.get("id"),
            "name": data.get("name"),
            "slug": data.get("slug"),
        }
