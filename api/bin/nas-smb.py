#!/usr/bin/env python3
"""Read one SMB directory. JSON on stdin/stdout; never echo credentials or errors."""
import json
import sys

sys.dont_write_bytecode = True


def main():
    try:
        sys.path.insert(0, sys.argv[1])
        import smbclient
        from smbprotocol.exceptions import NoMoreFiles, NoSuchFile
        from smbprotocol.file_info import FileAttributes, FileInformationClass
        from smbprotocol.open import (CreateDisposition, CreateOptions, DirectoryAccessMask,
                                      ImpersonationLevel, Open, QueryDirectoryFlags, ShareAccess)
        from smbprotocol.tree import TreeConnect
    except (ImportError, IndexError):
        return {"ok": False, "code": "runtime"}

    try:
        mapping = json.loads(sys.stdin.buffer.read(32769))
        username = mapping["username"]
        if mapping.get("domain"):
            username = mapping["domain"] + "\\" + username
        session = smbclient.register_session(
            mapping["host"], username=username, password=mapping["password"],
            port=445, connection_timeout=8, auth_protocol="ntlm", require_signing=True,
        )
        # Open exactly this share/directory. No high-level DFS or symlink resolver.
        tree = TreeConnect(session, "\\\\" + mapping["host"] + "\\" + mapping["share"])
        tree.connect()
        directory = Open(tree, mapping["folder"])
        directory.create(
            ImpersonationLevel.Impersonation, DirectoryAccessMask.FILE_LIST_DIRECTORY,
            FileAttributes.FILE_ATTRIBUTE_DIRECTORY,
            ShareAccess.FILE_SHARE_READ | ShareAccess.FILE_SHARE_WRITE | ShareAccess.FILE_SHARE_DELETE,
            CreateDisposition.FILE_OPEN, CreateOptions.FILE_DIRECTORY_FILE,
        )
        entries = []
        truncated = False
        try:
            flags = QueryDirectoryFlags.SMB2_RESTART_SCANS
            while not truncated:
                try:
                    batch = directory.query_directory("*", FileInformationClass.FILE_DIRECTORY_INFORMATION, flags=flags)
                except (NoMoreFiles, NoSuchFile):
                    break
                if not batch:
                    break
                flags = 0
                for info in batch:
                    name = info["file_name"].get_value().decode("utf-16-le")
                    if name in (".", ".."):
                        continue
                    if len(entries) >= 100:
                        truncated = True
                        break
                    attrs = info["file_attributes"].get_value()
                    entries.append({
                        "name": name,
                        "is_dir": bool(attrs & FileAttributes.FILE_ATTRIBUTE_DIRECTORY) and not bool(attrs & FileAttributes.FILE_ATTRIBUTE_REPARSE_POINT),
                        "size": info["end_of_file"].get_value(),
                        "modified_at": info["last_write_time"].get_value().isoformat(),
                    })
        finally:
            directory.close()
        entries.sort(key=lambda entry: (not entry["is_dir"], entry["name"].casefold()))
        return {"ok": True, "entries": entries, "truncated": truncated}
    except Exception as exc:
        # SMB exceptions can include UNC paths; only return a fixed error code.
        status = getattr(exc, "ntstatus", getattr(exc, "status", None))
        if status in (0xC000006D, 0xC000006A, 0xC0000064, 0xC0000071, 0xC0000072, 0xC0000234):
            code = "auth"
        elif status in (0xC0000022, 0xC00000CA):
            code = "denied"
        elif status in (0xC000000F, 0xC0000034, 0xC000003A, 0xC00000CC, 0xC0000225, 0xC0000103):
            code = "missing"
        elif isinstance(exc, (TimeoutError, ConnectionError)) or type(exc).__name__ in ("SMBConnectionClosed", "SMBConnectionError"):
            code = "network"
        elif type(exc).__name__ in ("SMBAuthenticationError", "SpnegoError"):
            code = "auth"
        elif type(exc).__name__ in ("SMBUnsupportedFeature", "PathNotCovered", "StoppedOnSymlink"):
            code = "unsupported"
        elif isinstance(exc, OSError) and status is None:
            code = "network"
        else:
            code = "unknown"
        return {"ok": False, "code": code}
    finally:
        smbclient.reset_connection_cache(fail_on_error=False)


if __name__ == "__main__":
    print(json.dumps(main(), ensure_ascii=False))
