"""Atomic upload fixtures, no live NAS."""
import hashlib
import importlib.util
import pathlib
import sys
import tempfile
from unittest.mock import MagicMock, patch

sys.dont_write_bytecode = True
sys.path.insert(0, sys.argv[1])
from smbprotocol.exceptions import ObjectNameNotFound
from smbprotocol.open import CreateDisposition, CreateOptions
from smbprotocol.file_info import FileAttributes

spec = importlib.util.spec_from_file_location("uploader", pathlib.Path(__file__).parents[1] / "bin/nas-upload.py")
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)
checks = 0


def check(condition, message):
    global checks
    assert condition, message
    checks += 1


with tempfile.TemporaryDirectory() as directory:
    content = b"%PDF-1.4\n" + b"Fixture document " * 10000
    file = pathlib.Path(directory) / "fixture.pdf"
    file.write_bytes(content)
    mapping = {"filename": "FM-IT-010_SV-TEST_1.pdf", "local_file": str(file), "sha256": hashlib.sha256(content).hexdigest(), "size": len(content), "folder": "IT"}
    existing, temp = MagicMock(), MagicMock()
    existing.create.side_effect = ObjectNameNotFound()
    storage = bytearray()

    def write(data, offset=0, **kwargs):
        storage[offset:offset + len(data)] = data
        return len(data)

    temp.write.side_effect = write
    temp.read.side_effect = lambda offset, length: bytes(storage[offset:offset + length])
    operations = []
    with patch("smbprotocol.open.Open", side_effect=[existing, temp]) as opened, patch.object(helper, "set_info", side_effect=lambda fd, info: operations.append(info)):
        result = helper.upload(mapping, MagicMock())
        check(result["ok"] and not result["existing"], "New PDF uploaded")
        check(storage == content and temp.write.call_count > 1, "Chunked writes preserve every byte")
        check(temp.create.call_args.args[4] == CreateDisposition.FILE_CREATE, "Never overwrite an existing object")
        check(not operations[0]["replace_if_exists"].get_value(), "Atomic rename refuses to replace existing files")
        check(operations[0]["file_name"].get_value() == "IT\\" + mapping["filename"], "Final file stays inside mapped folder")
        check(temp.flush.call_count == 1 and temp.close.call_count == 1, "Flush, verify, rename and close")
    existing = MagicMock(end_of_file=len(content))
    existing.read.side_effect = lambda offset, length: content[offset:offset + length]
    with patch("smbprotocol.open.Open", return_value=existing) as opened:
        result = helper.upload(mapping, MagicMock())
        check(result["ok"] and result["existing"] and opened.call_count == 1, "Retry verifies existing identical PDF without writing")
    existing.read.side_effect = lambda offset, length: b"X" * length
    with patch("smbprotocol.open.Open", return_value=existing):
        check(helper.upload(mapping, MagicMock())["code"] == "conflict", "Conflicting PDF is never overwritten")
    temp = MagicMock()
    temp.write.side_effect = OSError("Fixture failure")
    existing.create.side_effect = ObjectNameNotFound()
    with patch("smbprotocol.open.Open", side_effect=[existing, temp]), patch.object(helper, "set_info") as cleanup:
        try:
            helper.upload(mapping, MagicMock())
            raise AssertionError("Expected failure")
        except OSError:
            check(cleanup.call_args.args[1]["delete_pending"].get_value(), "Failed upload deletes partial file")
            check(temp.close.call_count == 1, "Failed upload closes its handle")
    for bad in ("../bad.pdf", "folder\\bad.pdf", "bad.exe"):
        try:
            helper.upload({**mapping, "filename": bad}, MagicMock())
            raise AssertionError("Expected invalid name")
        except ValueError:
            checks += 1
    # Typed subdirectories are created/opened without replacing any existing object.
    directory_handle = MagicMock(file_attributes=FileAttributes.FILE_ATTRIBUTE_DIRECTORY)
    with patch("smbprotocol.open.Open", return_value=directory_handle) as opened:
        check(helper.ensure_subfolder(MagicMock(), "IT", "FM-IT-010") == "IT\\FM-IT-010", "Subfolder stays under the configured folder")
        check(directory_handle.create.call_args.args[4] == CreateDisposition.FILE_OPEN_IF, "Create missing form folder, retain existing folders")
        check(directory_handle.create.call_args.args[5] & CreateOptions.FILE_OPEN_REPARSE_POINT, "Do not resolve directory reparse points")
        check(directory_handle.close.call_count == 1, "Directory handle closes")
    directory_handle.file_attributes = FileAttributes.FILE_ATTRIBUTE_DIRECTORY | FileAttributes.FILE_ATTRIBUTE_REPARSE_POINT
    with patch("smbprotocol.open.Open", return_value=directory_handle):
        try:
            helper.ensure_subfolder(MagicMock(), "IT", "FM-IT-010")
            raise AssertionError("Expected reparse rejection")
        except ValueError:
            checks += 1
    existing = MagicMock(end_of_file=len(content))
    existing.read.side_effect = lambda offset, length: content[offset:offset + length]
    with patch.object(helper, "ensure_subfolder", return_value="IT\\FM-IT-010"), patch("smbprotocol.open.Open", return_value=existing) as opened:
        check(helper.upload({**mapping, "subfolder": "FM-IT-010"}, MagicMock())["existing"], "Existing PDF is verified within its form folder")
        check(opened.call_args.args[1] == "IT\\FM-IT-010\\" + mapping["filename"], "Target includes the category directory")
    for invalid in ("../FM-IT-010", "FM-IT-010\\other", "FM-IT-008", "", "/tmp"):
        try:
            helper.upload({**mapping, "subfolder": invalid, "operation": "relocate"}, MagicMock())
            raise AssertionError("Expected invalid folder")
        except ValueError:
            checks += 1
    # Relocating existing archives never rewrites file contents.
    move_mapping = {**mapping, "subfolder": "FM-IT-010", "operation": "relocate"}
    target, source = MagicMock(), MagicMock(end_of_file=len(content))
    target.create.side_effect = ObjectNameNotFound()
    source.read.side_effect = lambda offset, length: content[offset:offset + length]
    with patch.object(helper, "ensure_subfolder", return_value="IT\\FM-IT-010"), patch("smbprotocol.open.Open", side_effect=[target, source]), patch.object(helper, "set_info") as renamed:
        result = helper.upload(move_mapping, MagicMock())
        check(result["ok"] and result["moved"], "Verified flat PDF is moved atomically")
        check(source.write.call_count == 0 and not renamed.call_args.args[1]["replace_if_exists"].get_value(), "Move never rewrites bytes or replaces another target")
        check(renamed.call_args.args[1]["file_name"].get_value() == "IT\\FM-IT-010\\" + mapping["filename"], "Rename uses the correct destination")
    source.read.side_effect = lambda offset, length: b"X" * length
    with patch.object(helper, "ensure_subfolder", return_value="IT\\FM-IT-010"), patch("smbprotocol.open.Open", side_effect=[target, source]), patch.object(helper, "set_info") as renamed:
        check(helper.upload(move_mapping, MagicMock())["code"] == "conflict" and not renamed.called, "Mismatch in original archive prevents moving or deleting it")
    target, source = MagicMock(end_of_file=len(content)), MagicMock(end_of_file=len(content))
    for handle in (target, source):
        handle.read.side_effect = lambda offset, length: content[offset:offset + length]
    with patch.object(helper, "ensure_subfolder", return_value="IT\\FM-IT-010"), patch("smbprotocol.open.Open", side_effect=[target, source]), patch.object(helper, "set_info") as removed:
        check(helper.upload(move_mapping, MagicMock())["ok"] and removed.call_args.args[1]["delete_pending"].get_value(), "Matching destination removes only a byte-verified root duplicate")
    source.create.side_effect = ObjectNameNotFound()
    with patch.object(helper, "ensure_subfolder", return_value="IT\\FM-IT-010"), patch("smbprotocol.open.Open", side_effect=[target, source]), patch.object(helper, "set_info") as removed:
        check(helper.upload(move_mapping, MagicMock())["ok"] and not removed.called, "Interrupted migration resumes if rename already completed")
print(f"PASS: {checks} atomic NAS upload checks; no network")
