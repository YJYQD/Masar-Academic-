#!/usr/bin/env python3
import argparse
import json
import os
import re
import sys
from typing import Any, Dict, List, Optional, Tuple

import mysql.connector
from mysql.connector import Error as MySQLError

try:
    from transformers import pipeline
except Exception:  # pragma: no cover - optional dependency
    pipeline = None


DEFAULT_MODEL = os.getenv("AI_SENTIMENT_MODEL", "wessam/AraBERT-sentiment")

KEYWORD_BANK = {
    "تميز الشرح": ["شرح", "مفهوم", "واضح", "مبسط", "سهل", "تفصيل", "توضيح", "شرح", "فهم"],
    "تميز التعامل": ["تعامل", "احترام", "مساعد", "دعم", "تواصل", "مرن", "مفهوم", "ودود", "لطف"],
    "تميز الاختبارات": ["اختبار", "امتحان", "تقدير", "grading", "quiz", "exam", "final", "معدل", "تقييم"],
}

POSITIVE_WORDS = [
    "ممتاز", "رائع", "مفيد", "جيد", "حلو", "مقبول", "متميز", "مؤثر", "سهل", "واضح", "مناسب", "افضل", "أعجبني",
    "أحسنت", "ممتازة", "رائعة", "مريحة", "مبسط", "مناسب", "مبهر", "مشكور", "ممتاز", "excellent", "good", "great"
]
NEGATIVE_WORDS = [
    "سيئ", "ضعيف", "مزعج", "ممل", "محبط", "صعب", "مربك", "غير واضح", "غير مناسب", "سيئة", "سئ", "bad", "poor",
    "terrible", "hard", "confusing", "annoying"
]


def load_db_config() -> Dict[str, Any]:
    return {
        "host": os.getenv("DB_HOST", "127.0.0.1"),
        "port": int(os.getenv("DB_PORT", "3306")),
        "user": os.getenv("DB_USER", "root"),
        "password": os.getenv("DB_PASS") or os.getenv("DB_PASSWORD") or "",
        "database": os.getenv("DB_NAME", "doctors_eval"),
        "charset": "utf8mb4",
        "use_pure": True,
    }


def get_connection() -> mysql.connector.MySQLConnection:
    cfg = load_db_config()
    return mysql.connector.connect(**cfg)


def ensure_ai_analysis_table(conn: mysql.connector.MySQLConnection) -> None:
    cursor = conn.cursor()
    cursor.execute(
        """
        CREATE TABLE IF NOT EXISTS ai_analysis (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            doctor_id INT UNSIGNED NOT NULL,
            doctor_name VARCHAR(255) DEFAULT NULL,
            college VARCHAR(255) DEFAULT NULL,
            department VARCHAR(255) DEFAULT NULL,
            review_count INT UNSIGNED NOT NULL DEFAULT 0,
            positive_ratio DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
            negative_ratio DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
            neutral_ratio DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
            avg_rating DECIMAL(3,2) NOT NULL DEFAULT 0.00,
            excellence_category VARCHAR(100) NOT NULL DEFAULT 'عام',
            keywords JSON DEFAULT NULL,
            last_analyzed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ai_doctor (doctor_id),
            KEY idx_ai_college (college),
            KEY idx_ai_category (excellence_category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        """
    )
    conn.commit()
    cursor.close()


def normalize_text(text: str) -> str:
    text = re.sub(r"\s+", " ", text or "")
    return text.strip()


def load_sentiment_model() -> Optional[Any]:
    if pipeline is None:
        print("[INFO] transformers is not available; using a fallback heuristic analyzer.", file=sys.stderr)
        return None

    try:
        model_name = os.getenv("AI_SENTIMENT_MODEL", DEFAULT_MODEL)
        print(f"[INFO] Loading sentiment model: {model_name}")
        return pipeline("text-classification", model=model_name, tokenizer=model_name, truncation=True)
    except Exception as exc:  # pragma: no cover - network/model dependent
        print(f"[WARN] Failed to load {DEFAULT_MODEL}: {exc}. Falling back to heuristic analyzer.", file=sys.stderr)
        return None


def predict_sentiment(text: str, model: Optional[Any]) -> Tuple[str, float]:
    text = normalize_text(text)
    if not text:
        return "neutral", 0.0

    if model is not None:
        try:
            result = model(text[:512])[0]
            label = str(result.get("label", "")).lower()
            score = float(result.get("score", 0.0) or 0.0)
            if any(token in label for token in ["pos", "positive", "1", "label_1"]):
                return "positive", score
            if any(token in label for token in ["neg", "negative", "0", "label_0"]):
                return "negative", score
            return "neutral", score
        except Exception as exc:  # pragma: no cover - model dependent
            print(f"[WARN] Prediction failed: {exc}", file=sys.stderr)

    positive_hits = sum(1 for word in POSITIVE_WORDS if word.lower() in text.lower())
    negative_hits = sum(1 for word in NEGATIVE_WORDS if word.lower() in text.lower())

    if positive_hits > negative_hits:
        return "positive", 1.0
    if negative_hits > positive_hits:
        return "negative", 1.0
    return "neutral", 0.0


def classify_excellence(text: str) -> str:
    text = normalize_text(text).lower()
    scores = {name: 0 for name in KEYWORD_BANK}

    for category, terms in KEYWORD_BANK.items():
        for term in terms:
            if term.lower() in text:
                scores[category] += 1

    if not any(scores.values()):
        return "عام"

    best_category = max(scores, key=scores.get)
    if scores[best_category] == 0:
        return "عام"
    return best_category


def extract_keywords(text: str) -> List[str]:
    text = normalize_text(text).lower()
    matched = []
    for _, terms in KEYWORD_BANK.items():
        for term in terms:
            if term.lower() in text and term not in matched:
                matched.append(term)
    return matched[:6]


def fetch_reviews(conn: mysql.connector.MySQLConnection) -> List[Dict[str, Any]]:
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """
        SELECT r.id, r.doctor_id, r.rating, r.comment, r.status,
               d.name AS doctor_name, d.college, d.department
        FROM reviews r
        LEFT JOIN doctors d ON d.id = r.doctor_id
        ORDER BY r.id DESC
        """
    )
    rows = cursor.fetchall()
    cursor.close()
    return rows


def analyze_reviews(conn: mysql.connector.MySQLConnection, model: Optional[Any]) -> List[Dict[str, Any]]:
    reviews = fetch_reviews(conn)
    if not reviews:
        return []

    grouped: Dict[int, Dict[str, Any]] = {}

    for row in reviews:
        doctor_id = int(row["doctor_id"])
        if doctor_id not in grouped:
            grouped[doctor_id] = {
                "doctor_id": doctor_id,
                "doctor_name": row.get("doctor_name") or "غير معروف",
                "college": row.get("college") or "غير محدد",
                "department": row.get("department") or "غير محدد",
                "review_count": 0,
                "positive_count": 0,
                "negative_count": 0,
                "neutral_count": 0,
                "rating_total": 0,
                "categories": {},
                "keywords": [],
            }

        entry = grouped[doctor_id]
        entry["review_count"] += 1
        entry["rating_total"] += int(row.get("rating") or 0)

        sentiment, _ = predict_sentiment(row.get("comment") or "", model)
        if sentiment == "positive":
            entry["positive_count"] += 1
        elif sentiment == "negative":
            entry["negative_count"] += 1
        else:
            entry["neutral_count"] += 1

        category = classify_excellence(row.get("comment") or "")
        entry["categories"][category] = entry["categories"].get(category, 0) + 1
        entry["keywords"].extend(extract_keywords(row.get("comment") or ""))

    results: List[Dict[str, Any]] = []
    for doctor_id, entry in grouped.items():
        total = entry["review_count"]
        positive_ratio = round(entry["positive_count"] / total, 4) if total else 0.0
        negative_ratio = round(entry["negative_count"] / total, 4) if total else 0.0
        neutral_ratio = round(entry["neutral_count"] / total, 4) if total else 0.0
        avg_rating = round(entry["rating_total"] / total, 2) if total else 0.0

        category = max(entry["categories"], key=lambda k: entry["categories"][k], default="عام") if entry["categories"] else "عام"
        keywords = []
        for keyword in entry["keywords"]:
            if keyword not in keywords:
                keywords.append(keyword)

        results.append(
            {
                "doctor_id": doctor_id,
                "doctor_name": entry["doctor_name"],
                "college": entry["college"],
                "department": entry["department"],
                "review_count": total,
                "positive_ratio": positive_ratio,
                "negative_ratio": negative_ratio,
                "neutral_ratio": neutral_ratio,
                "avg_rating": avg_rating,
                "excellence_category": category,
                "keywords": keywords,
            }
        )

    return results


def save_results(conn: mysql.connector.MySQLConnection, results: List[Dict[str, Any]]) -> int:
    cursor = conn.cursor()
    saved = 0
    for row in results:
        cursor.execute(
            """
            INSERT INTO ai_analysis (
                doctor_id, doctor_name, college, department, review_count,
                positive_ratio, negative_ratio, neutral_ratio, avg_rating,
                excellence_category, keywords
            ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                doctor_name = VALUES(doctor_name),
                college = VALUES(college),
                department = VALUES(department),
                review_count = VALUES(review_count),
                positive_ratio = VALUES(positive_ratio),
                negative_ratio = VALUES(negative_ratio),
                neutral_ratio = VALUES(neutral_ratio),
                avg_rating = VALUES(avg_rating),
                excellence_category = VALUES(excellence_category),
                keywords = VALUES(keywords),
                last_analyzed_at = CURRENT_TIMESTAMP
            """,
            (
                int(row["doctor_id"]),
                row["doctor_name"],
                row["college"],
                row["department"],
                int(row["review_count"]),
                float(row["positive_ratio"]),
                float(row["negative_ratio"]),
                float(row["neutral_ratio"]),
                float(row["avg_rating"]),
                row["excellence_category"],
                json.dumps(row["keywords"], ensure_ascii=False),
            ),
        )
        saved += 1

    conn.commit()
    cursor.close()
    return saved


def main() -> int:
    parser = argparse.ArgumentParser(description="Analyze doctor reviews and update ai_analysis")
    parser.add_argument("--dry-run", action="store_true", help="Show the number of records that would be processed without saving")
    args = parser.parse_args()

    conn = None
    try:
        conn = get_connection()
        ensure_ai_analysis_table(conn)
        model = load_sentiment_model()
        results = analyze_reviews(conn, model)
        if args.dry_run:
            print(f"[DRY-RUN] Would analyze {len(results)} doctors and update ai_analysis")
            return 0

        saved = save_results(conn, results)
        print(f"[OK] Processed {len(results)} doctor profiles and saved {saved} updates to ai_analysis")
        return 0
    except MySQLError as exc:
        print(f"[DB-ERROR] {exc}", file=sys.stderr)
        return 2
    finally:
        if conn is not None and conn.is_connected():
            conn.close()


if __name__ == "__main__":
    raise SystemExit(main())
