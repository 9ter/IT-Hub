#!/usr/bin/env python3
"""Upload a verified PDF atomically to the exact configured SMB share; secrets on stdin only."""
import hashlib
import json
import os
import re
import secrets
import sys

sys.dont_write_bytecode = True


def set_info(fd, info):
    from smbprotocol.open import SMB2SetInfoRequest, SMB2SetInfoResponse
    request = SMB2SetInfoRequest()
    request["info_type"] = info.INFO_TYPE
    request["file_info_class"] = info.INFO_CLASS
    request["file_id"] = fd.file_id
    request["buffer"] = info
    sent = fd.connection.send(request, sid=fd.tree_connect.session.session_id, tid=fd.tree_connect.tree_connect_id)
    result = fd.connection.receive(sent)
    response = SMB2SetInfoResponse()
    response.unpack(result["data"].get_value())


def digest(fd, size):
    result = hashlib.sha256()
    offset = 0
    while offset < size:
        block = fd.read(offset, min(65536, size - offset))
        if not block:
            raise ValueError("incomplete")
        result.update(block)
        offset += len(block)
    return result.hexdigest()


def ensure_subfolder(tree, folder, subfolder):
    from smbprotocol.open import Open, ImpersonationLevel, DirectoryAccessMask, CreateDisposition, CreateOptions, ShareAccess
    from smbprotocol.file_info import FileAttributes
    path = (folder + "\\" if folder else "") + subfolder
    directory = Open(tree, path)
    directory.create(ImpersonationLevel.Impersonation, DirectoryAccessMask.FILE_LIST_DIRECTORY | DirectoryAccessMask.FILE_READ_ATTRIBUTES,
                     FileAttributes.FILE_ATTRIBUTE_DIRECTORY,
                     ShareAccess.FILE_SHARE_READ | ShareAccess.FILE_SHARE_WRITE | ShareAccess.FILE_SHARE_DELETE,
                     CreateDisposition.FILE_OPEN_IF, CreateOptions.FILE_DIRECTORY_FILE | CreateOptions.FILE_OPEN_REPARSE_POINT)
    try:
        if directory.file_attributes & FileAttributes.FILE_ATTRIBUTE_REPARSE_POINT:
            raise ValueError("reparse")
    finally:
        directory.close()
    return path


def relocate(mapping, tree, target, target_exists=False):
    """Move only a known, verified flat archive; never overwrite another file."""
    from smbprotocol.open import Open, ImpersonationLevel, FilePipePrinterAccessMask as Access, CreateDisposition, CreateOptions
    from smbprotocol.file_info import FileAttributes, FileRenameInformation, FileDispositionInformation
    folder = mapping.get("folder", "")
    source = Open(tree, (folder + "\\" if folder else "") + mapping["filename"])
    try:
        source.create(ImpersonationLevel.Impersonation, Access.FILE_READ_DATA | Access.FILE_READ_ATTRIBUTES | Access.DELETE,
                      FileAttributes.FILE_ATTRIBUTE_NORMAL, 0, CreateDisposition.FILE_OPEN,
                      CreateOptions.FILE_NON_DIRECTORY_FILE | CreateOptions.FILE_OPEN_REPARSE_POINT)
    except Exception as exc:
        if target_exists and getattr(exc, "ntstatus", getattr(exc, "status", None)) in (0xC000000F, 0xC0000034):
            return {"ok": True, "existing": True, "moved": False}
        raise
    try:
        if source.end_of_file != mapping["size"] or digest(source, mapping["size"]) != mapping["sha256"]:
            return {"ok": False, "code": "conflict"}
        if target_exists:
            delete = FileDispositionInformation()
            delete["delete_pending"] = True
            set_info(source, delete)
        else:
            rename = FileRenameInformation()
            rename["replace_if_exists"] = False
            rename["root_directory"] = 0
            rename["file_name"] = target
            set_info(source, rename)
    finally:
        source.close()
    return {"ok": True, "existing": target_exists, "moved": True}


def upload(mapping, tree):
    from smbprotocol.open import (Open, ImpersonationLevel, FilePipePrinterAccessMask as Access,
                                  CreateDisposition, CreateOptions, ShareAccess)
    from smbprotocol.file_info import FileAttributes, FileRenameInformation, FileDispositionInformation
    name = mapping["filename"]
    if not re.fullmatch(r"[A-Z0-9._-]+\.pdf", name) or ".." in name:
        raise ValueError("name")
    size = mapping["size"]
    if not isinstance(size, int) or not 100 <= size <= 26214400:
        raise ValueError("size")
    moving = mapping.get("operation") == "relocate"
    content = None
    if not moving:
        with open(mapping["local_file"], "rb") as source:
            content = source.read(26214401)
        if len(content) != size or not content.startswith(b"%PDF-") or hashlib.sha256(content).hexdigest() != mapping["sha256"]:
            raise ValueError("content")
    folder = mapping.get("folder", "")
    subfolder = mapping.get("subfolder", "")
    if subfolder:
        if not re.fullmatch(r"FM-IT-\d{3}(?:\.1)?", subfolder) or not name.startswith(subfolder + "_"):
            raise ValueError("subfolder")
        folder = ensure_subfolder(tree, folder, subfolder)
    elif moving:
        raise ValueError("subfolder")
    target = (folder + "\\" if folder else "") + name
    existing = Open(tree, target)
    try:
        existing.create(ImpersonationLevel.Impersonation, Access.FILE_READ_DATA | Access.FILE_READ_ATTRIBUTES,
                        FileAttributes.FILE_ATTRIBUTE_NORMAL, ShareAccess.FILE_SHARE_READ,
                        CreateDisposition.FILE_OPEN, CreateOptions.FILE_NON_DIRECTORY_FILE | CreateOptions.FILE_OPEN_REPARSE_POINT)
    except Exception as exc:
        if getattr(exc, "ntstatus", getattr(exc, "status", None)) not in (0xC000000F, 0xC0000034):
            raise
    else:
        matched = False
        try:
            if existing.end_of_file != size or digest(existing, size) != mapping["sha256"]:
                return {"ok": False, "code": "conflict"}
            matched = True
        finally:
            existing.close()
        if matched:
            return relocate(mapping, tree, target, True) if moving else {"ok": True, "sha256": mapping["sha256"], "size": size, "existing": True}
    if moving:
        return relocate(mapping, tree, target)
    temp = Open(tree, (folder + "\\" if folder else "") + ".IT-Hub-" + secrets.token_hex(16) + ".partial")
    renamed = False
    temp.create(ImpersonationLevel.Impersonation, Access.FILE_READ_DATA | Access.FILE_WRITE_DATA | Access.DELETE,
                FileAttributes.FILE_ATTRIBUTE_NORMAL, 0, CreateDisposition.FILE_CREATE,
                CreateOptions.FILE_NON_DIRECTORY_FILE | CreateOptions.FILE_OPEN_REPARSE_POINT)
    try:
        offset = 0
        while offset < size:
            count = temp.write(content[offset:offset + 65536], offset=offset, write_through=True)
            if count <= 0:
                raise ValueError("write")
            offset += count
        temp.flush()
        if digest(temp, size) != mapping["sha256"]:
            raise ValueError("verify")
        rename = FileRenameInformation()
        rename["replace_if_exists"] = False
        rename["root_directory"] = 0
        rename["file_name"] = target
        set_info(temp, rename)
        renamed = True
    finally:
        if not renamed:
            try:
                delete = FileDispositionInformation()
                delete["delete_pending"] = True
                set_info(temp, delete)
            except Exception:
                pass
        temp.close()
    return {"ok": True, "sha256": mapping["sha256"], "size": size, "existing": False}


def main():
    try:
        sys.path.insert(0, sys.argv[1])
        import smbclient
        from smbprotocol.tree import TreeConnect
    except (ImportError, IndexError):
        return {"ok": False, "code": "runtime"}
    try:
        mapping = json.loads(sys.stdin.buffer.read(32769))
        username = (mapping.get("domain", "") + "\\" if mapping.get("domain") else "") + mapping["username"]
        session = smbclient.register_session(mapping["host"], username=username, password=mapping["password"],
                                             port=445, connection_timeout=8, auth_protocol="ntlm", require_signing=True)
        tree = TreeConnect(session, "\\\\" + mapping["host"] + "\\" + mapping["share"])
        tree.connect()
        return upload(mapping, tree)
    except Exception as exc:
        status = getattr(exc, "ntstatus", getattr(exc, "status", None))
        if status in (0xC000006D, 0xC000006A, 0xC0000064, 0xC0000071, 0xC0000072, 0xC0000234):
            code = "auth"
        elif status in (0xC0000022, 0xC00000CA):
            code = "denied"
        elif status == 0xC0000035:
            code = "conflict"
        else:
            code = "upload"
        return {"ok": False, "code": code}
    finally:
        smbclient.reset_connection_cache(fail_on_error=False)


if __name__ == "__main__":
    print(json.dumps(main()))
