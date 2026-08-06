from pathlib import Path

source_path = Path('../tech/.github/scripts/apply_d2_terminal_dedup.py')
source = source_path.read_text(encoding='utf-8')


def unwrap_dedent_assignment(name: str, suffix: str) -> None:
    global source
    start_marker = f'{name} = dedent("""'
    start = source.index(start_marker)
    source = source[:start] + f'{name} = """' + source[start + len(start_marker):]
    end = source.index(suffix, start)
    replacement = suffix.replace('""")', '"""', 1)
    source = source[:end] + replacement + source[end + len(suffix):]


unwrap_dedent_assignment('terminal_block', '""").lstrip(\'\\n\')')
unwrap_dedent_assignment('new_terminal_update', '""").strip(\'\\n\')')
unwrap_dedent_assignment('listener_old', '""").lstrip(\'\\n\')')
unwrap_dedent_assignment('listener_new', '""").lstrip(\'\\n\')')

exec(compile(source, str(source_path), 'exec'), {'__name__': '__main__'})
