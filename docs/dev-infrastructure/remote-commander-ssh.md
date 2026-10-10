# Remote Desktop Commander → Mac → VDS SSH (password entered once)

## Purpose and how it works

Remote Desktop Commander is connected to an authorized Mac and can execute commands on it. The user manually authenticates to `deploy@mskba.ru` **in the Mac Terminal**. OpenSSH `ControlMaster` keeps a reusable, authenticated SSH connection accessible through a **local Unix socket**. The assistant can then run SSH commands from the same Mac through Remote Desktop Commander using that socket without handling the password.

```
ChatGPT → Remote Desktop Commander → authorized Mac
                                       └─ local SSH control socket → VDS deploy@mskba.ru
```

**This is temporary session reuse, not passwordless login and not persistent authorization.** The control socket is local to that Mac. It can stop working after SSH closes, the persistence interval expires, networking/VPN changes, or the computer sleeps. A fresh master connection generally requires manual password authentication again. Never send passwords into chat, the repository, command-line arguments, or connector logs.

## Establish the connection (user action)

1. Ensure Remote Desktop Commander is connected to the intended Mac. If SSH stalls during banner exchange, change/disconnect the VPN and confirm ordinary SSH works before proceeding; this occurred during the October 2026 staging setup.
2. In a **Mac Terminal** window, the user runs:

   ```bash
   ssh -M -S /tmp/mskba-diagnostic.sock -o ControlPersist=15m deploy@mskba.ru
   ```

3. The user enters the SSH password **locally** at the Terminal prompt. Do not ask the user to disclose it to ChatGPT. Successful login should show a remote `deploy@...` prompt. `-M` enables a master connection, `-S` sets the local socket path, and `ControlPersist=15m` retains the master for up to 15 minutes *after the interactive SSH session closes* (not an absolute connection lifetime). Keep the master alive while doing work, or reconnect if it expires.

The command and socket path above were recovered from the October 7 conversation. On that occasion, the assistant could not inject keystrokes into the Mac Terminal reliably due to Accessibility restrictions, so the user pasted the connection command and typed the password manually.

## Run commands from Remote Desktop Commander

The assistant uses the authorized Mac's terminal execution capability, not an SSH session inside ChatGPT itself. For example:

```bash
ssh -S /tmp/mskba-diagnostic.sock -o BatchMode=yes deploy@mskba.ru 'hostname'
ssh -S /tmp/mskba-diagnostic.sock -o BatchMode=yes deploy@mskba.ru 'cd /var/www/mskba-dev-next && git status -sb'
```

`BatchMode=yes` makes a failed reuse/authentication attempt fail rather than waiting for interactive credentials. Check the exit status and output before taking any further action. Before mutating the VDS, verify the remote working directory and identity, ask for authorization appropriate to the operation, and preserve production boundaries. For MSKBA staging, use `/var/www/mskba-dev-next`; do not assume a generic `docker` command is safe for production.

A quick local socket check:

```bash
ssh -S /tmp/mskba-diagnostic.sock -O check deploy@mskba.ru
```

To deliberately close a still-running master:

```bash
ssh -S /tmp/mskba-diagnostic.sock -O exit deploy@mskba.ru
```

Only issue `-O exit` when the user wants the shared connection closed; it may interrupt other users of this socket. If the socket is stale, do **not** reuse it as evidence of authorization. Re-establish authentication in the Mac Terminal. Do not delete an active socket belonging to another task.

## Troubleshooting and safety

- `Control socket connect ... No such file` or `Connection refused`: the master may have expired. Ask the user to repeat the initial login.
- `Connection timed out during banner exchange`: this is before password authentication. During October 2026 work, switching VPN location restored access from the Mac, while GitHub Actions SSH continued working. Test VPN routing before modifying `sshd`.
- Connection prompts for password unexpectedly: do not script password entry. Verify the master/socket; let the user authenticate in Terminal.
- A GitHub Actions deploy key is **separate** from this Mac Terminal login. A successful GitHub preflight does not prove Mac SSH access.
- Set restrictive access on the Mac account and avoid placing control sockets in a shared directory when multiple local users are untrusted. `/tmp/mskba-diagnostic.sock` is the historical path, not a general recommendation for shared machines. Consider `~/.ssh/control/` with mode `0700` for future sessions.
- Password entry authorizes access only within the SSH user's privileges. Never request or paste production credentials or expose `.env` secrets in logs.

## Project policy

Use this approach for explicitly authorized, interactive maintenance when direct access through Remote Desktop Commander is needed. Prefer the existing tested GitHub Actions CI/CD pipeline for routine dev deployment. Manual SSH changes to staging must not bypass review of backups, data migrations, secrets, and production isolation.
