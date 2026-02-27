import tkinter as tk
from tkinter import ttk, messagebox, filedialog
import threading
import csv
import os

import config_manager
from wp_api import WordPressAPI


class App:
    def __init__(self, root):
        self.root = root
        self.root.title("WordPress Password Manager")
        self.root.geometry("750x620")
        self.root.minsize(650, 550)

        self.sites = config_manager.load_sites()

        self._build_ui()
        self._refresh_site_list()

    # ── UI Construction ──────────────────────────────────────────

    def _build_ui(self):
        pad = {"padx": 10, "pady": 5}

        # ── Section 1: Sites list ─────────────────────────────────
        frame_sites = ttk.LabelFrame(self.root, text="Strony WordPress")
        frame_sites.pack(fill="both", expand=True, **pad)

        # Treeview with columns
        columns = ("name", "url", "username")
        self.tree = ttk.Treeview(
            frame_sites, columns=columns, show="headings", height=8
        )
        self.tree.heading("name", text="Nazwa")
        self.tree.heading("url", text="URL")
        self.tree.heading("username", text="Login")
        self.tree.column("name", width=150)
        self.tree.column("url", width=300)
        self.tree.column("username", width=120)

        scrollbar = ttk.Scrollbar(
            frame_sites, orient="vertical", command=self.tree.yview
        )
        self.tree.configure(yscrollcommand=scrollbar.set)

        self.tree.pack(side="left", fill="both", expand=True, padx=(10, 0), pady=5)
        scrollbar.pack(side="left", fill="y", pady=5)

        # Buttons panel
        btn_frame = ttk.Frame(frame_sites)
        btn_frame.pack(side="left", fill="y", padx=10, pady=5)

        ttk.Button(btn_frame, text="Dodaj", command=self._add_site, width=14).pack(pady=2)
        ttk.Button(btn_frame, text="Edytuj", command=self._edit_site, width=14).pack(pady=2)
        ttk.Button(btn_frame, text="Usun", command=self._remove_site, width=14).pack(pady=2)
        ttk.Separator(btn_frame, orient="horizontal").pack(fill="x", pady=6)
        ttk.Button(btn_frame, text="Importuj CSV", command=self._import_csv, width=14).pack(pady=2)
        ttk.Button(btn_frame, text="Eksportuj CSV", command=self._export_csv, width=14).pack(pady=2)
        ttk.Separator(btn_frame, orient="horizontal").pack(fill="x", pady=6)
        ttk.Button(btn_frame, text="Testuj", command=self._test_connection, width=14).pack(pady=2)
        ttk.Button(btn_frame, text="Testuj wszystkie", command=self._test_all, width=14).pack(pady=2)

        # ── Section 2: Password ───────────────────────────────────
        frame_pass = ttk.LabelFrame(self.root, text="Nowe haslo")
        frame_pass.pack(fill="x", **pad)

        inner = ttk.Frame(frame_pass)
        inner.pack(fill="x", padx=10, pady=8)

        ttk.Label(inner, text="Haslo:").pack(side="left")
        self.password_var = tk.StringVar()
        self.password_entry = ttk.Entry(inner, textvariable=self.password_var, width=40, show="*")
        self.password_entry.pack(side="left", padx=(5, 10))

        self.show_pass_var = tk.BooleanVar(value=False)
        ttk.Checkbutton(
            inner, text="Pokaz", variable=self.show_pass_var,
            command=self._toggle_password_visibility
        ).pack(side="left", padx=(0, 20))

        ttk.Button(
            inner, text="Zmien haslo - zaznaczone",
            command=self._change_selected
        ).pack(side="left", padx=5)

        ttk.Button(
            inner, text="Zmien haslo - WSZYSTKIE",
            command=self._change_all
        ).pack(side="left", padx=5)

        # ── Section 3: Results ────────────────────────────────────
        frame_log = ttk.LabelFrame(self.root, text="Wyniki")
        frame_log.pack(fill="both", expand=True, **pad)

        self.log_text = tk.Text(frame_log, height=8, state="disabled", wrap="word")
        log_scroll = ttk.Scrollbar(
            frame_log, orient="vertical", command=self.log_text.yview
        )
        self.log_text.configure(yscrollcommand=log_scroll.set)
        self.log_text.pack(side="left", fill="both", expand=True, padx=(10, 0), pady=5)
        log_scroll.pack(side="left", fill="y", pady=5, padx=(0, 10))

        # Configure text tags for colored output
        self.log_text.tag_configure("success", foreground="green")
        self.log_text.tag_configure("error", foreground="red")
        self.log_text.tag_configure("info", foreground="blue")

    # ── Site List ─────────────────────────────────────────────────

    def _refresh_site_list(self):
        self.tree.delete(*self.tree.get_children())
        for site in self.sites:
            self.tree.insert("", "end", values=(
                site["name"], site["url"], site["username"]
            ))

    def _get_selected_indices(self):
        """Return list of indices for selected treeview rows."""
        items = self.tree.selection()
        all_items = self.tree.get_children()
        return [all_items.index(item) for item in items]

    # ── Add / Edit / Remove ───────────────────────────────────────

    def _add_site(self):
        self._site_dialog("Dodaj strone", self._on_add_site)

    def _on_add_site(self, name, url, username, app_password):
        for s in self.sites:
            if s["name"] == name:
                messagebox.showerror("Blad", f"Strona '{name}' juz istnieje.")
                return False
        self.sites = config_manager.add_site(name, url, username, app_password)
        self._refresh_site_list()
        return True

    def _edit_site(self):
        indices = self._get_selected_indices()
        if len(indices) != 1:
            messagebox.showwarning("Uwaga", "Zaznacz dokladnie jedna strone do edycji.")
            return
        idx = indices[0]
        site = self.sites[idx]
        self._site_dialog(
            "Edytuj strone",
            lambda n, u, us, ap: self._on_edit_site(idx, n, u, us, ap),
            prefill=site,
        )

    def _on_edit_site(self, index, name, url, username, app_password):
        for i, s in enumerate(self.sites):
            if s["name"] == name and i != index:
                messagebox.showerror("Blad", f"Strona '{name}' juz istnieje.")
                return False
        self.sites = config_manager.update_site(index, name, url, username, app_password)
        self._refresh_site_list()
        return True

    def _remove_site(self):
        indices = self._get_selected_indices()
        if not indices:
            messagebox.showwarning("Uwaga", "Zaznacz strony do usuniecia.")
            return
        names = [self.sites[i]["name"] for i in indices]
        if not messagebox.askyesno(
            "Potwierdzenie",
            f"Usunac {len(names)} stron(e)?\n" + "\n".join(names)
        ):
            return
        for idx in sorted(indices, reverse=True):
            self.sites = config_manager.remove_site(idx)
        self._refresh_site_list()

    def _site_dialog(self, title, callback, prefill=None):
        dlg = tk.Toplevel(self.root)
        dlg.title(title)
        dlg.geometry("450x220")
        dlg.transient(self.root)
        dlg.grab_set()

        fields = [
            ("Nazwa:", "name"),
            ("URL (https://):", "url"),
            ("Login (username):", "username"),
            ("Application Password:", "app_password"),
        ]
        entries = {}
        for row, (label, key) in enumerate(fields):
            ttk.Label(dlg, text=label).grid(row=row, column=0, padx=10, pady=5, sticky="e")
            ent = ttk.Entry(dlg, width=40)
            ent.grid(row=row, column=1, padx=10, pady=5, sticky="w")
            if prefill and key in prefill:
                ent.insert(0, prefill[key])
            entries[key] = ent

        def on_ok():
            vals = {k: e.get().strip() for k, e in entries.items()}
            if not all(vals.values()):
                messagebox.showwarning("Uwaga", "Wypelnij wszystkie pola.", parent=dlg)
                return
            if not vals["url"].startswith("http"):
                vals["url"] = "https://" + vals["url"]
            result = callback(vals["name"], vals["url"], vals["username"], vals["app_password"])
            if result is not False:
                dlg.destroy()

        btn_frame = ttk.Frame(dlg)
        btn_frame.grid(row=len(fields), column=0, columnspan=2, pady=15)
        ttk.Button(btn_frame, text="OK", command=on_ok, width=12).pack(side="left", padx=5)
        ttk.Button(btn_frame, text="Anuluj", command=dlg.destroy, width=12).pack(side="left", padx=5)

    # ── CSV Import / Export ─────────────────────────────────────────

    def _import_csv(self):
        path = filedialog.askopenfilename(
            title="Wybierz plik CSV",
            filetypes=[("CSV files", "*.csv"), ("All files", "*.*")],
        )
        if not path:
            return

        imported = 0
        skipped = 0
        errors = []
        existing_names = {s["name"] for s in self.sites}

        try:
            with open(path, "r", encoding="utf-8-sig") as f:
                reader = csv.DictReader(f, delimiter=";")

                # Validate required columns
                required = {"name", "url", "username", "app_password"}
                if not reader.fieldnames or not required.issubset(set(reader.fieldnames)):
                    missing = required - set(reader.fieldnames or [])
                    messagebox.showerror(
                        "Blad CSV",
                        f"Brak wymaganych kolumn: {', '.join(missing)}\n\n"
                        f"Wymagane kolumny: name;url;username;app_password"
                    )
                    return

                for row_num, row in enumerate(reader, start=2):
                    name = row.get("name", "").strip()
                    url = row.get("url", "").strip()
                    username = row.get("username", "").strip()
                    app_password = row.get("app_password", "").strip()

                    if not all([name, url, username, app_password]):
                        errors.append(f"Wiersz {row_num}: puste pola, pominieto")
                        skipped += 1
                        continue

                    if name in existing_names:
                        errors.append(f"Wiersz {row_num}: '{name}' juz istnieje, pominieto")
                        skipped += 1
                        continue

                    if not url.startswith("http"):
                        url = "https://" + url

                    self.sites = config_manager.add_site(name, url, username, app_password)
                    existing_names.add(name)
                    imported += 1

        except Exception as e:
            messagebox.showerror("Blad", f"Nie udalo sie odczytac CSV:\n{e}")
            return

        self._refresh_site_list()

        msg = f"Zaimportowano: {imported}\nPominieto: {skipped}"
        if errors:
            msg += "\n\nSzczegoly:\n" + "\n".join(errors[:20])
        messagebox.showinfo("Import CSV", msg)

    def _export_csv(self):
        if not self.sites:
            messagebox.showwarning("Uwaga", "Brak stron do eksportu.")
            return

        path = filedialog.asksaveasfilename(
            title="Zapisz CSV",
            defaultextension=".csv",
            filetypes=[("CSV files", "*.csv")],
            initialfile="wordpress_sites.csv",
        )
        if not path:
            return

        try:
            with open(path, "w", encoding="utf-8-sig", newline="") as f:
                writer = csv.DictWriter(
                    f,
                    fieldnames=["name", "url", "username", "app_password"],
                    delimiter=";",
                )
                writer.writeheader()
                for site in self.sites:
                    writer.writerow({
                        "name": site["name"],
                        "url": site["url"],
                        "username": site["username"],
                        "app_password": site["app_password"],
                    })
            messagebox.showinfo("Eksport CSV", f"Wyeksportowano {len(self.sites)} stron do:\n{path}")
        except Exception as e:
            messagebox.showerror("Blad", f"Nie udalo sie zapisac CSV:\n{e}")

    # ── Test Connection ───────────────────────────────────────────

    def _test_connection(self):
        indices = self._get_selected_indices()
        if not indices:
            messagebox.showwarning("Uwaga", "Zaznacz strony do testu.")
            return
        self._run_threaded(self._do_test, indices)

    def _test_all(self):
        if not self.sites:
            messagebox.showwarning("Uwaga", "Brak stron na liscie.")
            return
        indices = list(range(len(self.sites)))
        self._run_threaded(self._do_test, indices)

    def _do_test(self, indices):
        self._log_clear()
        for idx in indices:
            site = self.sites[idx]
            self._log(f"Testuje: {site['name']} ({site['url']})...\n", "info")
            try:
                api = WordPressAPI(site["url"], site["username"], site["app_password"])
                info = api.test_connection()
                self._log(
                    f"  OK - Zalogowano jako: {info['name']} "
                    f"(ID: {info['id']}, role: {', '.join(info['roles'])})\n",
                    "success",
                )
            except Exception as e:
                self._log(f"  BLAD - {e}\n", "error")

    # ── Password Toggle ───────────────────────────────────────────

    def _toggle_password_visibility(self):
        if self.show_pass_var.get():
            self.password_entry.config(show="")
        else:
            self.password_entry.config(show="*")
        # Initialize hidden on first build
        if not hasattr(self, "_pass_init"):
            self.password_entry.config(show="*")
            self._pass_init = True

    # ── Change Password ───────────────────────────────────────────

    def _change_selected(self):
        indices = self._get_selected_indices()
        if not indices:
            messagebox.showwarning("Uwaga", "Zaznacz strony do zmiany hasla.")
            return
        self._change_password(indices)

    def _change_all(self):
        if not self.sites:
            messagebox.showwarning("Uwaga", "Brak stron na liscie.")
            return
        names = [s["name"] for s in self.sites]
        if not messagebox.askyesno(
            "Potwierdzenie",
            f"Zmieniac haslo na WSZYSTKICH {len(names)} stronach?\n" + "\n".join(names)
        ):
            return
        self._change_password(list(range(len(self.sites))))

    def _change_password(self, indices):
        new_pass = self.password_var.get()
        if not new_pass:
            messagebox.showwarning("Uwaga", "Wpisz nowe haslo.")
            return
        if len(new_pass) < 6:
            messagebox.showwarning("Uwaga", "Haslo powinno miec co najmniej 6 znakow.")
            return
        self._run_threaded(self._do_change_password, indices, new_pass)

    def _do_change_password(self, indices, new_pass):
        self._log_clear()
        ok_count = 0
        fail_count = 0
        for idx in indices:
            site = self.sites[idx]
            self._log(f"Zmieniam haslo: {site['name']} ({site['url']})...\n", "info")
            try:
                api = WordPressAPI(site["url"], site["username"], site["app_password"])
                info = api.change_password(new_pass)
                self._log(
                    f"  OK - Haslo zmienione dla: {info['name']} (ID: {info['id']})\n",
                    "success",
                )
                ok_count += 1
            except Exception as e:
                self._log(f"  BLAD - {e}\n", "error")
                fail_count += 1

        self._log(f"\n--- Podsumowanie ---\n", "info")
        self._log(f"Sukces: {ok_count}  |  Bledy: {fail_count}\n", "info")

    # ── Logging ───────────────────────────────────────────────────

    def _log_clear(self):
        self.root.after(0, self._do_log_clear)

    def _do_log_clear(self):
        self.log_text.config(state="normal")
        self.log_text.delete("1.0", "end")
        self.log_text.config(state="disabled")

    def _log(self, text, tag=None):
        self.root.after(0, self._do_log, text, tag)

    def _do_log(self, text, tag):
        self.log_text.config(state="normal")
        if tag:
            self.log_text.insert("end", text, tag)
        else:
            self.log_text.insert("end", text)
        self.log_text.see("end")
        self.log_text.config(state="disabled")

    # ── Threading helper ──────────────────────────────────────────

    def _run_threaded(self, func, *args):
        thread = threading.Thread(target=func, args=args, daemon=True)
        thread.start()
