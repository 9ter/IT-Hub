"""Transport checks using SMB response fixtures; never connects to a NAS."""
import datetime
import importlib.util
import io
import json
import pathlib
import sys
from unittest.mock import MagicMock, patch

sys.dont_write_bytecode = True
packages = sys.argv[1] if len(sys.argv) > 1 else "/var/lib/it-hub/python"
sys.path.insert(0, packages)
from smbprotocol.exceptions import LogonFailure, NoMoreFiles, NoSuchFile, ObjectPathNotFound, PathNotCovered
from smbprotocol.file_info import FileAttributes
from smbprotocol.open import CreateDisposition, DirectoryAccessMask

spec = importlib.util.spec_from_file_location("nas_helper", pathlib.Path(__file__).parents[1] / "bin/nas-smb.py")
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)
mapping = {"host": "192.168.1.10", "share": "Documents", "folder": "แผนก IT", "username": "fixture", "domain": "DOMAIN", "password": "FakeSecret!"}
checks = 0


class Value:
    def __init__(self, value):
        self.value = value

    def get_value(self):
        return self.value


def entry(name, attributes=0):
    return {key: Value(value) for key, value in {
        "file_name": name.encode("utf-16-le"), "file_attributes": attributes, "end_of_file": 2048,
        "last_write_time": datetime.datetime(2026, 10, 28, 9, tzinfo=datetime.timezone.utc),
    }.items()}


def check(condition, label):
    global checks
    assert condition, label
    checks += 1
    print(f"OK {checks} {label}")


def run(batches=None, open_error=None, auth_error=None):
    directory = MagicMock()
    directory.query_directory.side_effect = batches or [NoMoreFiles()]
    directory.create.side_effect = open_error
    fake_stdin = MagicMock(buffer=io.BytesIO(json.dumps(mapping).encode()))
    with patch.object(sys, "stdin", fake_stdin), patch.object(sys, "argv", ["nas-smb.py", packages]), \
            patch("smbclient.register_session", side_effect=auth_error) as login, \
            patch("smbprotocol.tree.TreeConnect") as tree, \
            patch("smbprotocol.open.Open", return_value=directory) as opened, \
            patch("smbclient.scandir", side_effect=AssertionError("High-level resolver must not run")), \
            patch("smbclient.reset_connection_cache") as cleanup:
        result = helper.main()
        check(cleanup.call_count == 1, "Transport always closes session cache")
        return result, login, tree, opened, directory


result, login, tree, opened, directory = run([[entry("."), entry(".."), entry("เอกสาร.pdf"), entry("โฟลเดอร์", FileAttributes.FILE_ATTRIBUTE_DIRECTORY)] , NoMoreFiles()])
check(result["ok"] and [e["name"] for e in result["entries"]] == ["โฟลเดอร์", "เอกสาร.pdf"], "Unicode listing skips dot entries and sorts directories first")
check(result["entries"][1]["size"] == 2048 and result["entries"][1]["modified_at"].endswith("+00:00"), "Preserve file size and timestamp timezone")
check(login.call_args.kwargs["require_signing"] and login.call_args.kwargs["username"] == "DOMAIN\\fixture", "Signed SMB session uses explicit domain/account")
check(tree.call_args.args[1] == "\\\\192.168.1.10\\Documents" and opened.call_args.args[1] == "แผนก IT", "Open exactly the selected share and folder")
check(directory.create.call_args.args[1] == DirectoryAccessMask.FILE_LIST_DIRECTORY and directory.create.call_args.args[4] == CreateDisposition.FILE_OPEN, "Only request directory listing access, never create or write")
check(directory.close.call_count == 1, "Directory handle is closed")
check(not result["truncated"], "Short listing is complete")
result, *_ = run([[entry(f"file{i}") for i in range(75)], [entry(f"file{i}") for i in range(75, 102)]])
check(len(result["entries"]) == 100 and result["truncated"], "Limit multi-batch directory listing to 100 entries")
result, *_ = run([[entry(f"file{i}") for i in range(100)], NoMoreFiles()])
check(len(result["entries"]) == 100 and not result["truncated"], "Exactly 100 files are not incorrectly marked truncated")
result, *_ = run([NoSuchFile()])
check(result["ok"] and result["entries"] == [], "No matches is an accessible empty directory")
result, *_ = run(open_error=ObjectPathNotFound())
check(result == {"ok": False, "code": "missing"}, "Missing target maps to a fixed error code")
result, login, *_ = run(open_error=PathNotCovered())
check(result == {"ok": False, "code": "unsupported"} and login.call_count == 1, "DFS target is rejected without following a referral")
result, *_ = run(auth_error=LogonFailure())
check(result == {"ok": False, "code": "auth"}, "Authentication failure returns no account or password data")
result, *_ = run(open_error=RuntimeError(mapping["password"]))
check(result == {"ok": False, "code": "unknown"} and mapping["password"] not in json.dumps(result), "Exception details and secrets never reach stdout")
print(f"PASS {checks} SMB transport checks; fixture responses only")
