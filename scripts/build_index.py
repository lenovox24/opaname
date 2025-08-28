#!/usr/bin/env python3
import hashlib
import json
import os
from pathlib import Path
from datetime import datetime, timezone
from typing import Dict, Iterable, List, Optional


EXCLUDED_DIR_NAMES = {
    ".git",
    ".hg",
    ".svn",
    "node_modules",
    "vendor",
    ".venv",
    "venv",
    "dist",
    "build",
    ".next",
    ".cache",
    ".turbo",
    "target",
    "out",
    ".idea",
    ".vscode",
    "__pycache__",
}

# Simple heuristic mapping from file extension to language name
EXTENSION_TO_LANGUAGE = {
    # web
    ".js": "JavaScript",
    ".jsx": "JavaScript",
    ".ts": "TypeScript",
    ".tsx": "TypeScript",
    ".mjs": "JavaScript",
    ".cjs": "JavaScript",
    ".css": "CSS",
    ".scss": "SCSS",
    ".sass": "Sass",
    ".less": "Less",
    ".html": "HTML",
    ".vue": "Vue",
    ".svelte": "Svelte",
    ".astro": "Astro",

    # python
    ".py": "Python",
    ".pyi": "Python",

    # compiled
    ".c": "C",
    ".h": "C",
    ".cc": "C++",
    ".cpp": "C++",
    ".cxx": "C++",
    ".hpp": "C++",
    ".hh": "C++",
    ".rs": "Rust",
    ".go": "Go",
    ".java": "Java",
    ".kt": "Kotlin",
    ".kts": "Kotlin",
    ".swift": "Swift",

    # dotnet
    ".cs": "C#",
    ".fs": "F#",
    ".vb": "VB.NET",

    # scripting
    ".sh": "Shell",
    ".bash": "Shell",
    ".zsh": "Shell",
    ".ps1": "PowerShell",
    ".psm1": "PowerShell",
    ".rb": "Ruby",
    ".php": "PHP",
    ".pl": "Perl",

    # data/config
    ".json": "JSON",
    ".jsonc": "JSONC",
    ".yaml": "YAML",
    ".yml": "YAML",
    ".toml": "TOML",
    ".ini": "INI",
    ".env": "ENV",
    ".xml": "XML",
    ".csv": "CSV",
    ".md": "Markdown",
    ".rst": "reStructuredText",
    ".txt": "Text",
    ".sql": "SQL",
    ".prisma": "Prisma",
    ".graphql": "GraphQL",
    ".gql": "GraphQL",
    ".proto": "Protobuf",
    ".dockerfile": "Dockerfile",
    ".dockerignore": "Docker",
    ".gitignore": "Git",
    ".editorconfig": "EditorConfig",
}


def path_is_excluded(path: Path) -> bool:
    for part in path.parts:
        if part in EXCLUDED_DIR_NAMES:
            return True
    return False


def is_probably_text(sample: bytes) -> bool:
    if not sample:
        return True
    if b"\x00" in sample:
        return False
    # Consider it text if most bytes are printable or whitespace
    printable = sum(32 <= b <= 126 or b in (9, 10, 13) for b in sample)
    return printable / max(1, len(sample)) >= 0.85


def detect_language(path: Path) -> Optional[str]:
    name_lower = path.name.lower()
    if name_lower == "dockerfile":
        return "Dockerfile"
    ext = path.suffix.lower()
    return EXTENSION_TO_LANGUAGE.get(ext)


def compute_sha256(file_path: Path) -> str:
    hasher = hashlib.sha256()
    with file_path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            hasher.update(chunk)
    return hasher.hexdigest()


def iter_files(root: Path) -> Iterable[Path]:
    for path in root.rglob("*"):
        if not path.is_file():
            continue
        if path_is_excluded(path.relative_to(root)):
            continue
        if path.is_symlink():
            continue
        yield path


def ensure_dir(path: Path) -> None:
    path.mkdir(parents=True, exist_ok=True)


def format_iso(ts: float) -> str:
    return datetime.fromtimestamp(ts, tz=timezone.utc).isoformat()


def main() -> None:
    repo_root = Path(os.environ.get("REPO_ROOT", ".")).resolve()
    output_dir = repo_root / ".code_index"
    ensure_dir(output_dir)

    index_path = output_dir / "index.jsonl"
    summary_path = output_dir / "summary.json"

    total_files = 0
    total_bytes = 0
    language_to_count: Dict[str, int] = {}
    text_files = 0
    binary_files = 0

    with index_path.open("w", encoding="utf-8") as index_file:
        for file_path in iter_files(repo_root):
            rel_path = file_path.relative_to(repo_root).as_posix()

            try:
                stat = file_path.stat()
                size_bytes = stat.st_size
                mtime_iso = format_iso(stat.st_mtime)

                with file_path.open("rb") as f:
                    sample = f.read(4096)
                is_text = is_probably_text(sample)

                sha256 = compute_sha256(file_path)
                language = detect_language(file_path)

                record = {
                    "path": rel_path,
                    "size_bytes": size_bytes,
                    "sha256": sha256,
                    "is_text": is_text,
                    "language": language,
                    "modified_iso": mtime_iso,
                }
                index_file.write(json.dumps(record, ensure_ascii=False) + "\n")

                total_files += 1
                total_bytes += size_bytes
                if is_text:
                    text_files += 1
                else:
                    binary_files += 1
                if language:
                    language_to_count[language] = language_to_count.get(language, 0) + 1

            except Exception as exc:
                error_record = {
                    "path": rel_path,
                    "error": str(exc),
                }
                index_file.write(json.dumps(error_record, ensure_ascii=False) + "\n")

    summary = {
        "repo_root": str(repo_root),
        "total_files": total_files,
        "total_bytes": total_bytes,
        "text_files": text_files,
        "binary_files": binary_files,
        "language_counts": language_to_count,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "index_file": str(index_path),
    }
    with summary_path.open("w", encoding="utf-8") as f:
        json.dump(summary, f, ensure_ascii=False, indent=2)

    print(f"Indexed {total_files} files (text: {text_files}, binary: {binary_files}) into {index_path}")
    print(f"Summary written to {summary_path}")


if __name__ == "__main__":
    main()