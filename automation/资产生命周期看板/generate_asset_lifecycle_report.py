#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Generate an asset lifecycle workbook from the Snipe-IT Excel activity log."""

from __future__ import annotations

import argparse
from collections import Counter, defaultdict
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Any

from openpyxl import Workbook, load_workbook
from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
from openpyxl.utils import get_column_letter
from openpyxl.worksheet.table import Table, TableStyleInfo


SOURCE_PATH = Path(r"D:\snipe-it\asset_activity.xlsx")
OUTPUT_PATH = Path(r"D:\snipe-it\资产生命周期查询.xlsx")
SHEET_NAME = "ActivityLog"

FONT_NAME = "Microsoft YaHei"
PRIMARY = "1F4E78"
PRIMARY_DARK = "17365D"
LIGHT_BLUE = "EAF3F8"
PALE_BLUE = "F6FAFD"
BORDER_GRAY = "D9E2EC"
TEXT_DARK = "1F2933"
WARNING = "FFF2CC"
DANGER = "F4CCCC"
SUCCESS = "D9EAD3"

STATUS_LABELS = {
    "入库",
    "领用",
    "借用中",
    "归还",
    "退租",
    "维修中",
    "维修完成已重新入库",
    "已换新",
    "删除资产",
}

STATUS_COLORS = {
    "入库": "D9EAD3",
    "领用": "DDEBF7",
    "借用中": "E4DFEC",
    "归还": "DDEFE8",
    "退租": "FCE4D6",
    "维修中": "FFF2CC",
    "维修完成已重新入库": "D9EAD3",
    "已换新": "E2F0D9",
    "删除资产": "E7E6E6",
    "未分类": "F3F6F8",
}

DETAIL_HEADERS = [
    "资产编号",
    "操作时间",
    "状态标签",
    "操作动作",
    "资产分类",
    "资产名称",
    "序列号",
    "使用人/对象",
    "操作人",
    "备注",
    "同步时间",
    "查询键",
]
DETAIL_VISIBLE_HEADERS = DETAIL_HEADERS[:-1]

INDEX_HEADERS = [
    "资产编号",
    "资产名称",
    "资产分类",
    "序列号",
    "当前状态",
    "当前使用人/对象",
    "首次记录时间",
    "最近操作时间",
    "总操作次数",
    "入库次数",
    "领用/借用次数",
    "归还次数",
    "维修次数",
    "退租/删除次数",
    "最近备注",
    "查询键",
]

QUERY_HISTORY_ROWS = 300
QUERY_KEY_HEADER = "查询键"


@dataclass
class ActivityRow:
    sync_time: str
    log_id: str
    action_time: datetime
    action: str
    status: str
    current_status: str
    category: str
    internal_id: str
    asset_tag: str
    asset_name: str
    serial: str
    operator: str
    target: str
    note: str
    meta: str


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Generate asset lifecycle report.")
    parser.add_argument("--source", type=Path, default=SOURCE_PATH)
    parser.add_argument("--output", type=Path, default=OUTPUT_PATH)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    rows = load_rows(args.source)
    workbook = build_workbook(rows)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    workbook.save(args.output)
    print(f"Generated {args.output}; assets={asset_count(rows)}; rows={len(rows)}")
    return 0


def load_rows(path: Path) -> list[ActivityRow]:
    if not path.exists():
        raise SystemExit(f"Source workbook not found: {path}")

    workbook = load_workbook(path, read_only=True, data_only=True)
    sheet = workbook[SHEET_NAME] if SHEET_NAME in workbook.sheetnames else workbook.active
    header_map = {
        safe_text(sheet.cell(1, col).value): col - 1
        for col in range(1, sheet.max_column + 1)
        if safe_text(sheet.cell(1, col).value)
    }

    rows: list[ActivityRow] = []
    for raw in sheet.iter_rows(min_row=2, max_col=sheet.max_column, values_only=True):
        row = list(raw)
        action_time = parse_datetime(get_value(row, header_map, "操作时间"))
        asset_tag = safe_text(get_value(row, header_map, "资产编号"))
        if not action_time or not asset_tag:
            continue
        status = clean_status(
            get_value(row, header_map, "状态标签", "操作后状态", "资产状态", "当前资产状态")
        )
        current_status = clean_status(
            get_value(row, header_map, "_同步时当前状态", "同步时当前状态", "当前资产状态")
        )
        rows.append(
            ActivityRow(
                sync_time=safe_text(get_value(row, header_map, "同步时间")),
                log_id=safe_text(get_value(row, header_map, "_日志ID", "日志ID")),
                action_time=action_time,
                action=safe_text(get_value(row, header_map, "操作动作", "操作类型", "操作类型(中文)")),
                status=status or current_status or "未分类",
                current_status=current_status,
                category=safe_text(get_value(row, header_map, "资产分类", "资产类型")) or "未填写",
                internal_id=safe_text(get_value(row, header_map, "_Snipe-IT内部ID", "Snipe-IT内部ID", "资产ID")),
                asset_tag=asset_tag,
                asset_name=safe_text(get_value(row, header_map, "资产名称")),
                serial=safe_text(get_value(row, header_map, "序列号")),
                operator=safe_text(get_value(row, header_map, "操作人")),
                target=safe_text(get_value(row, header_map, "使用人/对象", "对象/领用人")),
                note=safe_text(get_value(row, header_map, "备注")),
                meta=safe_text(get_value(row, header_map, "_变更详情(JSON)", "变更详情(JSON)")),
            )
        )

    workbook.close()
    rows.sort(key=lambda item: (item.asset_tag, item.action_time, item.log_id))
    return rows


def get_value(row: list[Any], header_map: dict[str, int], *names: str) -> Any:
    for name in names:
        index = header_map.get(name)
        if index is not None and index < len(row):
            return row[index]
    return ""


def build_workbook(rows: list[ActivityRow]) -> Workbook:
    workbook = Workbook()
    workbook.remove(workbook.active)
    workbook.calculation.fullCalcOnLoad = True
    workbook.calculation.forceFullCalc = True
    workbook.calculation.calcMode = "auto"

    grouped = group_by_asset(rows)
    add_index_sheet(workbook, grouped)
    add_detail_sheet(workbook, rows)
    add_readme_sheet(workbook)
    query_sheet = add_query_sheet(workbook, len(grouped))
    workbook.move_sheet(query_sheet, offset=-(len(workbook.sheetnames) - 1))
    return workbook


def group_by_asset(rows: list[ActivityRow]) -> dict[str, list[ActivityRow]]:
    grouped: dict[str, list[ActivityRow]] = defaultdict(list)
    for row in rows:
        grouped[row.asset_tag].append(row)
    return dict(sorted(grouped.items(), key=lambda item: item[0]))


def add_index_sheet(workbook: Workbook, grouped: dict[str, list[ActivityRow]]) -> None:
    sheet = workbook.create_sheet("资产索引")
    add_title(sheet, "资产生命周期查询", "每台资产一行，先在这里筛选资产编号，再到“生命周期明细”查看完整历史。", len(INDEX_HEADERS))
    write_headers(sheet, 4, INDEX_HEADERS)

    for asset_rows in grouped.values():
        asset_rows.sort(key=lambda item: (item.action_time, item.log_id))
        first = asset_rows[0]
        latest = asset_rows[-1]
        counts = Counter(row.status for row in asset_rows)
        sheet.append(
            [
                latest.asset_tag,
                latest.asset_name or first.asset_name,
                latest.category or first.category,
                latest.serial or first.serial,
                latest.status,
                current_holder(latest),
                first.action_time,
                latest.action_time,
                len(asset_rows),
                counts.get("入库", 0),
                counts.get("领用", 0) + counts.get("借用中", 0),
                counts.get("归还", 0),
                counts.get("维修中", 0) + counts.get("维修完成已重新入库", 0),
                counts.get("退租", 0) + counts.get("删除资产", 0),
                latest.note,
                normalize_asset_key(latest.asset_tag),
            ]
        )

    style_table(sheet, 4, 1, max(sheet.max_row, 4), len(INDEX_HEADERS), "LifecycleIndex")
    set_widths(sheet, [24, 26, 16, 20, 16, 18, 20, 20, 12, 12, 14, 12, 12, 14, 34, 14])
    sheet.column_dimensions[get_column_letter(len(INDEX_HEADERS))].hidden = True
    color_status_column(sheet, 5, 5, sheet.max_row)


def add_detail_sheet(workbook: Workbook, rows: list[ActivityRow]) -> None:
    sheet = workbook.create_sheet("生命周期明细")
    add_title(sheet, "生命周期明细", "按资产编号和操作时间排序，保留每一次状态变化记录。", len(DETAIL_HEADERS))
    write_headers(sheet, 4, DETAIL_HEADERS)

    for row in rows:
        sheet.append(
            [
                row.asset_tag,
                row.action_time,
                row.status,
                row.action,
                row.category,
                row.asset_name,
                row.serial,
                row.target,
                row.operator,
                row.note,
                row.sync_time,
                normalize_asset_key(row.asset_tag),
            ]
        )

    style_table(sheet, 4, 1, max(sheet.max_row, 4), len(DETAIL_HEADERS), "LifecycleDetail")
    set_widths(sheet, [24, 20, 20, 14, 16, 28, 20, 18, 14, 36, 20, 14])
    sheet.column_dimensions[get_column_letter(len(DETAIL_HEADERS))].hidden = True
    color_status_column(sheet, 3, 5, sheet.max_row)


def add_query_sheet(workbook: Workbook, asset_total: int) -> Any:
    sheet = workbook.create_sheet("资产查询")
    add_title(
        sheet,
        "资产查询",
        "在 B4 输入或选择资产编号，下方会自动显示当前信息和完整历史。",
        len(DETAIL_VISIBLE_HEADERS),
    )
    sheet.freeze_panes = "A18"

    sheet["A4"] = "资产编号"
    sheet["B4"] = ""
    sheet["D4"] = "资产总数"
    sheet["E4"] = asset_total
    sheet["G4"] = "更新时间"
    sheet["H4"] = datetime.now()
    sheet["H4"].number_format = "yyyy-mm-dd hh:mm:ss"

    for cell in ["A4", "D4", "G4"]:
        sheet[cell].font = Font(name=FONT_NAME, bold=True, color="FFFFFF")
        sheet[cell].fill = PatternFill("solid", fgColor=PRIMARY)
        sheet[cell].alignment = Alignment(horizontal="center", vertical="center")
    for cell in ["B4", "E4", "H4"]:
        sheet[cell].font = Font(name=FONT_NAME, color=TEXT_DARK)
        sheet[cell].fill = PatternFill("solid", fgColor="FFFFFF")
        sheet[cell].alignment = Alignment(vertical="center", wrap_text=True)
    sheet["B4"].fill = PatternFill("solid", fgColor=WARNING)

    add_query_summary(sheet)
    add_query_history(sheet)
    set_widths(sheet, [24, 28, 20, 16, 18, 28, 18, 24, 16, 36, 20])
    apply_range_border(sheet, 4, 1, 16, len(DETAIL_VISIBLE_HEADERS))
    return sheet


def add_query_summary(sheet: Any) -> None:
    labels = [
        ("A6", "当前状态", "B6", index_lookup_formula("当前状态")),
        ("C6", "当前使用人", "D6", index_lookup_formula("当前使用人/对象")),
        ("E6", "最近操作时间", "F6", index_lookup_formula("最近操作时间")),
        ("G6", "总操作次数", "H6", index_lookup_formula("总操作次数")),
        ("A8", "资产名称", "B8", index_lookup_formula("资产名称")),
        ("C8", "资产分类", "D8", index_lookup_formula("资产分类")),
        ("E8", "序列号", "F8", index_lookup_formula("序列号")),
        ("A10", "最近备注", "B10", index_lookup_formula("最近备注")),
    ]
    for label_cell, label, value_cell, formula in labels:
        sheet[label_cell] = label
        sheet[label_cell].font = Font(name=FONT_NAME, bold=True, color="FFFFFF")
        sheet[label_cell].fill = PatternFill("solid", fgColor=PRIMARY)
        sheet[label_cell].alignment = Alignment(horizontal="center", vertical="center")
        sheet[value_cell] = formula
        sheet[value_cell].font = Font(name=FONT_NAME, color=TEXT_DARK)
        sheet[value_cell].fill = PatternFill("solid", fgColor=PALE_BLUE)
        sheet[value_cell].alignment = Alignment(vertical="center", wrap_text=True)

    sheet.merge_cells("B10:H10")
    sheet["A12"] = "使用方式"
    sheet["A12"].font = Font(name=FONT_NAME, bold=True, color="FFFFFF")
    sheet["A12"].fill = PatternFill("solid", fgColor=PRIMARY_DARK)
    sheet.merge_cells("B12:H14")
    sheet["B12"] = (
        "1. 在 B4 直接粘贴或输入资产编号。\n"
        "2. 复制编号时如果带了换行、空格，查询会自动忽略。\n"
        "3. 上方会自动显示当前状态、当前使用人、最近操作和总操作次数。\n"
        f"4. 下方自动列出该资产最多前 {QUERY_HISTORY_ROWS} 条历史；如记录更多，可到“生命周期明细”筛选查看全部。"
    )
    sheet["B12"].font = Font(name=FONT_NAME, color=TEXT_DARK)
    sheet["B12"].fill = PatternFill("solid", fgColor=PALE_BLUE)
    sheet["B12"].alignment = Alignment(vertical="top", wrap_text=True)

    for cell in ["B6", "D6", "F6", "H6", "B8", "D8", "F8", "H8", "B10"]:
        if cell in {"F6"}:
            sheet[cell].number_format = "yyyy-mm-dd hh:mm:ss"
    sheet.row_dimensions[12].height = 24
    sheet.row_dimensions[13].height = 24
    sheet.row_dimensions[14].height = 24


def add_query_history(sheet: Any) -> None:
    sheet["A18"] = "资产编号"
    for col_index, header in enumerate(DETAIL_VISIBLE_HEADERS, start=1):
        cell = sheet.cell(18, col_index, header)
        cell.font = Font(name=FONT_NAME, bold=True, color="FFFFFF")
        cell.fill = PatternFill("solid", fgColor=PRIMARY)
        cell.alignment = Alignment(horizontal="center", vertical="center", wrap_text=True)
    sheet.row_dimensions[18].height = 28

    for offset in range(QUERY_HISTORY_ROWS):
        excel_row = 19 + offset
        rank = offset + 1
        key_column = get_column_letter(len(DETAIL_HEADERS))
        row_ref = (
            f"AGGREGATE(15,6,ROW('生命周期明细'!${key_column}$5:${key_column}$10000)/"
            f"('生命周期明细'!${key_column}$5:${key_column}$10000={normalized_query_formula()}),{rank})"
        )
        for col_index in range(1, len(DETAIL_VISIBLE_HEADERS) + 1):
            letter = get_column_letter(col_index)
            formula = (
                f'=IF($B$4="","",IFERROR(INDEX(\'生命周期明细\'!${letter}:${letter},{row_ref}),""))'
            )
            cell = sheet.cell(excel_row, col_index, formula)
            cell.font = Font(name=FONT_NAME, color=TEXT_DARK)
            cell.fill = PatternFill("solid", fgColor=PALE_BLUE if excel_row % 2 else "FFFFFF")
            cell.alignment = Alignment(vertical="center", wrap_text=True)
            if col_index in {2, 11}:
                cell.number_format = "yyyy-mm-dd hh:mm:ss"
        sheet.row_dimensions[excel_row].height = 26

    apply_range_border(sheet, 18, 1, 18 + QUERY_HISTORY_ROWS, len(DETAIL_VISIBLE_HEADERS))


def index_lookup_formula(field_name: str) -> str:
    column_map = {
        "资产名称": "B",
        "资产分类": "C",
        "序列号": "D",
        "当前状态": "E",
        "当前使用人/对象": "F",
        "最近操作时间": "H",
        "总操作次数": "I",
        "最近备注": "O",
    }
    column = column_map[field_name]
    query_key_column = get_column_letter(len(INDEX_HEADERS))
    return (
        '=IF($B$4="","",IFERROR('
        f'INDEX(\'资产索引\'!${column}:${column},MATCH({normalized_query_formula()},\'资产索引\'!${query_key_column}:${query_key_column},0)),'
        '"未找到"))'
    )


def normalized_query_formula() -> str:
    return 'UPPER(SUBSTITUTE(SUBSTITUTE(SUBSTITUTE(CLEAN(TRIM($B$4))," ",""),CHAR(160),""),CHAR(9),""))'


def add_readme_sheet(workbook: Workbook) -> None:
    sheet = workbook.create_sheet("使用说明")
    add_title(sheet, "使用说明", "这是正式版生命周期查询表，会在同步到新记录后自动刷新。", 3)
    rows = [
        ["资产查询", "最常用页面。在 B4 直接粘贴或输入资产编号，下方自动显示当前状态和历史明细。"],
        ["复制编号", "如果从系统复制编号时带了换行、普通空格或全角空格，查询页会自动忽略这些字符。"],
        ["资产索引", "每台资产一行，用来快速查看当前状态、当前使用人和最近操作。"],
        ["生命周期明细", "所有资产的完整历史记录，可以按资产编号筛选查看一台设备的全部流转。"],
        ["当前状态", "按该资产最近一条操作记录判断。后续如果你希望直接读 Snipe-IT 实时状态，也可以再增强。"],
        ["自动刷新", "监听程序同步到新资产记录后，会自动重新生成本文件。若文件正被打开，刷新可能延后到下次同步。"],
    ]
    write_headers(sheet, 4, ["位置", "说明"])
    for item in rows:
        sheet.append(item)
    style_table(sheet, 4, 1, sheet.max_row, 2, "LifecycleReadme")
    set_widths(sheet, [18, 80])


def current_holder(row: ActivityRow) -> str:
    if row.status in {"领用", "借用中"}:
        return row.target
    return ""


def add_title(sheet: Any, title: str, subtitle: str, column_count: int) -> None:
    sheet.sheet_view.showGridLines = False
    end_column = get_column_letter(column_count)
    sheet.merge_cells(f"A1:{end_column}1")
    sheet.merge_cells(f"A2:{end_column}2")
    sheet["A1"] = title
    sheet["A2"] = subtitle
    sheet["A1"].font = Font(name=FONT_NAME, size=18, bold=True, color="FFFFFF")
    sheet["A1"].fill = PatternFill("solid", fgColor=PRIMARY_DARK)
    sheet["A1"].alignment = Alignment(horizontal="center", vertical="center")
    sheet["A2"].font = Font(name=FONT_NAME, size=10, color=TEXT_DARK)
    sheet["A2"].fill = PatternFill("solid", fgColor=LIGHT_BLUE)
    sheet["A2"].alignment = Alignment(horizontal="center", vertical="center", wrap_text=True)
    sheet.row_dimensions[1].height = 34
    sheet.row_dimensions[2].height = 24


def write_headers(sheet: Any, row_index: int, headers: list[str]) -> None:
    for col_index, header in enumerate(headers, start=1):
        cell = sheet.cell(row_index, col_index, header)
        cell.font = Font(name=FONT_NAME, bold=True, color="FFFFFF")
        cell.fill = PatternFill("solid", fgColor=PRIMARY)
        cell.alignment = Alignment(horizontal="center", vertical="center", wrap_text=True)
    sheet.row_dimensions[row_index].height = 28


def style_table(
    sheet: Any,
    min_row: int,
    min_col: int,
    max_row: int,
    max_col: int,
    table_name: str,
) -> None:
    thin = Side(style="thin", color=BORDER_GRAY)
    border = Border(left=thin, right=thin, top=thin, bottom=thin)
    for row_index in range(min_row, max_row + 1):
        fill = PatternFill("solid", fgColor=PALE_BLUE if row_index % 2 else "FFFFFF")
        for col_index in range(min_col, max_col + 1):
            cell = sheet.cell(row_index, col_index)
            cell.border = border
            if row_index == min_row:
                continue
            cell.font = Font(name=FONT_NAME, color=TEXT_DARK)
            cell.fill = fill
            cell.alignment = Alignment(vertical="center", wrap_text=True)
        if row_index > min_row:
            sheet.row_dimensions[row_index].height = 28

    sheet.freeze_panes = f"A{min_row + 1}"
    sheet.auto_filter.ref = f"A{min_row}:{get_column_letter(max_col)}{max_row}"
    if max_row >= min_row:
        table = Table(displayName=table_name, ref=sheet.auto_filter.ref)
        table.tableStyleInfo = TableStyleInfo(
            name="TableStyleMedium2",
            showFirstColumn=False,
            showLastColumn=False,
            showRowStripes=True,
            showColumnStripes=False,
        )
        sheet.add_table(table)

    for row in sheet.iter_rows(min_row=min_row + 1, max_row=max_row):
        for cell in row:
            if isinstance(cell.value, datetime):
                cell.number_format = "yyyy-mm-dd hh:mm:ss"


def apply_range_border(sheet: Any, min_row: int, min_col: int, max_row: int, max_col: int) -> None:
    thin = Side(style="thin", color=BORDER_GRAY)
    border = Border(left=thin, right=thin, top=thin, bottom=thin)
    for row in sheet.iter_rows(
        min_row=min_row,
        min_col=min_col,
        max_row=max_row,
        max_col=max_col,
    ):
        for cell in row:
            cell.border = border


def color_status_column(sheet: Any, column: int, start_row: int, end_row: int) -> None:
    for row_index in range(start_row, end_row + 1):
        cell = sheet.cell(row_index, column)
        color = STATUS_COLORS.get(safe_text(cell.value))
        if color:
            cell.fill = PatternFill("solid", fgColor=color)
            cell.font = Font(name=FONT_NAME, color=TEXT_DARK, bold=True)
            cell.alignment = Alignment(horizontal="center", vertical="center", wrap_text=True)


def set_widths(sheet: Any, widths: list[int]) -> None:
    for index, width in enumerate(widths, start=1):
        sheet.column_dimensions[get_column_letter(index)].width = width


def asset_count(rows: list[ActivityRow]) -> int:
    return len({row.asset_tag for row in rows})


def parse_datetime(value: Any) -> datetime | None:
    if isinstance(value, datetime):
        return value
    text = safe_text(value)
    if not text:
        return None
    for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%d %H:%M"):
        try:
            return datetime.strptime(text, fmt)
        except ValueError:
            continue
    return None


def clean_status(value: Any) -> str:
    text = safe_text(value).strip().rstrip(".。…").strip()
    return text if text in STATUS_LABELS else (text or "")


def normalize_asset_key(value: Any) -> str:
    text = safe_text(value).upper()
    for item in ("\r", "\n", " ", "　"):
        text = text.replace(item, "")
    return text


def safe_text(value: Any) -> str:
    if value is None:
        return ""
    return str(value).strip()


if __name__ == "__main__":
    raise SystemExit(main())
