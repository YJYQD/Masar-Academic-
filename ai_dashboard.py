#!/usr/bin/env python3
import json
import os
from typing import Any, Dict, List, Optional

import pandas as pd
import streamlit as st
import mysql.connector
from mysql.connector import Error as MySQLError
import plotly.express as px

try:
    from langchain.prompts import PromptTemplate
    from langchain.schema import StrOutputParser
    from langchain_openai import ChatOpenAI
except Exception:  # pragma: no cover - optional dependency
    PromptTemplate = None
    StrOutputParser = None
    ChatOpenAI = None


st.set_page_config(page_title="لوحة تحليل الذكاء الاصطناعي", page_icon="🤖", layout="wide")

# ستايل احترافي لضبط المسافات وتقليل الفراغات لجعل كل شيء يظهر بوضوح بدون سكرول طويل
st.markdown("""
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap');
        html, body, [data-testid="stSidebar"], .stApp {
            font-family: 'Cairo', sans-serif;
            direction: rtl;
            text-align: right;
        }
        .metric-card {
            background-color: #1e222b;
            border: 1px solid #2d3139;
            padding: 1rem;
            border-radius: 10px;
            text-align: center;
            border-top: 4px solid #1d7bb6;
            margin-bottom: 10px;
        }
        .metric-card h3 { color: #aaa; font-size: 1rem; margin-bottom: 5px; }
        .metric-card h2 { color: #1d7bb6; font-size: 1.6rem; margin: 0; font-weight: 700; }
        .doctor-box {
            background-color: #1e222b;
            border-right: 5px solid #1d7bb6;
            padding: 12px;
            border-radius: 4px 10px 10px 4px;
            margin-bottom: 10px;
            border: 1px solid #2d3139;
            border-right: 5px solid #1d7bb6;
        }
        .stPlotlyChart {
            margin-bottom: -20px;
        }
    </style>
""", unsafe_allow_html=True)


@st.cache_data(show_spinner=False)
def load_dashboard_data() -> pd.DataFrame:
    cfg = {
        "host": os.getenv("DB_HOST", "127.0.0.1"),
        "port": int(os.getenv("DB_PORT", "3306")),
        "user": os.getenv("DB_USER", "root"),
        "password": os.getenv("DB_PASS") or os.getenv("DB_PASSWORD") or "",
        "database": os.getenv("DB_NAME", "doctors_eval"),
        "charset": "utf8mb4",
        "use_pure": True,
    }

    conn = mysql.connector.connect(**cfg)
    try:
        cursor = conn.cursor(dictionary=True)
        cursor.execute(
            """
            SELECT a.*, d.name AS doctor_name, d.college, d.department
            FROM ai_analysis a
            LEFT JOIN doctors d ON d.id = a.doctor_id
            ORDER BY a.positive_ratio DESC, a.review_count DESC
            """
        )
        rows = cursor.fetchall()
        cursor.close()
        if not rows:
            return pd.DataFrame(columns=[
                "doctor_id", "doctor_name", "college", "department", "review_count",
                "positive_ratio", "negative_ratio", "neutral_ratio", "avg_rating",
                "excellence_category", "keywords", "last_analyzed_at"
            ])

        df = pd.DataFrame(rows)
        df = df.rename(columns={"name": "doctor_name"})
        df["positive_ratio_pct"] = (df["positive_ratio"] * 100).round(1)
        df["avg_rating"] = pd.to_numeric(df["avg_rating"], errors="coerce").fillna(0)
        df["keywords"] = df["keywords"].apply(lambda value: json.loads(value) if isinstance(value, str) else value or [])
        return df
    finally:
        conn.close()


@st.cache_data(show_spinner=False)
def fetch_reviews_context(subject_name: str) -> str:
    cfg = {
        "host": os.getenv("DB_HOST", "127.0.0.1"),
        "port": int(os.getenv("DB_PORT", "3306")),
        "user": os.getenv("DB_USER", "root"),
        "password": os.getenv("DB_PASS") or os.getenv("DB_PASSWORD") or "",
        "database": os.getenv("DB_NAME", "doctors_eval"),
        "charset": "utf8mb4",
        "use_pure": True,
    }

    conn = mysql.connector.connect(**cfg)
    try:
        cursor = conn.cursor(dictionary=True)
        cursor.execute(
            """
            SELECT r.comment, r.rating, r.course_code, r.semester, d.name AS doctor_name, d.college, d.department
            FROM reviews r
            INNER JOIN doctors d ON d.id = r.doctor_id
            WHERE r.status = 'approved'
            ORDER BY r.id DESC
            LIMIT 200
            """
        )
        rows = cursor.fetchall()
        cursor.close()
        if not rows:
            return "لا توجد تقييمات حالياً."

        lines = []
        for row in rows:
            comment = (row.get("comment") or "").strip()
            if not comment:
                continue
            lines.append(
                f"دكتور: {row.get('doctor_name')} | كلية: {row.get('college')} | قسم: {row.get('department')} | مادة: {row.get('course_code') or 'غير محدد'} | تقييم: {row.get('rating')} | تعليق: {comment}"
            )
        return "\n".join(lines[:120])
    finally:
        conn.close()


def get_doctor_recommendation(subject_name: str) -> str:
    context = fetch_reviews_context(subject_name)
    if not context or context == "لا توجد تقييمات حالياً.":
        return "لا تتوفر تقييمات كافية حالياً لإعطاء توصية موثوقة."

    api_key = os.getenv("OPENAI_API_KEY", "")
    if ChatOpenAI and PromptTemplate and StrOutputParser and api_key:
        try:
            llm = ChatOpenAI(model="gpt-4o-mini", temperature=0, api_key=api_key)
            prompt = PromptTemplate.from_template(
                """
                أنت مساعد ذكي لجامعة جازان. استخدم التقييمات الحقيقية أدناه فقط.
                السؤال: {question}
                السياق:
                {context}

                أجب بصيغة مختصرة وبالعربية. إذا لم تجد تقييمًا واضحًا، قل إن المعلومات غير كافية.
                """
            )
            chain = prompt | llm | StrOutputParser()
            return chain.invoke({
                "question": f"من أفضل دكتور لمادة {subject_name} بناءً على التقييمات الحقيقية؟",
                "context": context,
            })
        except Exception as exc:  # pragma: no cover - external service dependent
            st.warning(f"فشل استدعاء OpenAI: {exc}")

    lines = [line for line in context.splitlines() if line.strip()]
    if not lines:
        return "لا توجد تقييمات كافية." 

    return (
        "استنادًا إلى التقييمات المتاحة، أفضّل أن أراجع التقييمات الحقيقية في قاعدة البيانات أولاً قبل إصدار توصية نهائية. "
        f"المادة: {subject_name}. آخر تقييمات المتاحة تشير إلى أن الدكاترة الأكثر تميزًا هم أولئك الذين حصلوا على تقييمات إيجابية واضحة في التعليقات."
    )


st.title("🤖 لوحة تحليلات الذكاء الاصطناعي")
st.markdown("<p style='font-size:1rem; color:#aaa; margin-top:-15px;'>قراءة مباشرة من قاعدة البيانات وملخص ذكي للدكاترة بناءً على تقييمات الطلاب</p>", unsafe_allow_html=True)

try:
    df = load_dashboard_data()
except MySQLError as exc:
    st.error(f"تعذر الاتصال بقاعدة البيانات: {exc}")
    st.stop()

if df.empty:
    st.info("لا توجد بيانات بعد في جدول ai_analysis. شغّل analyze_reviews.py أولاً.")
    st.stop()

# الكروت العلوية منسقة وصغيرة ومؤطرة
col1, col2, col3, col4 = st.columns(4)
with col1:
    st.markdown(f"<div class='metric-card'><h3>👥 إجمالي الدكاترة</h3><h2>{int(df['doctor_id'].nunique())}</h2></div>", unsafe_allow_html=True)
with col2:
    st.markdown(f"<div class='metric-card'><h3>📈 متوسط نسبة الرضا</h3><h2>{df['positive_ratio_pct'].mean():.1f}%</h2></div>", unsafe_allow_html=True)
with col3:
    top_cat = df["excellence_category"].mode().iloc[0] if not df["excellence_category"].mode().empty else "-"
    st.markdown(f"<div class='metric-card'><h3>✨ أعلى فئة تميز</h3><h2>{top_cat}</h2></div>", unsafe_allow_html=True)
with col4:
    st.markdown(f"<div class='metric-card'><h3>💬 التقييمات المجمعة</h3><h2>{int(df['review_count'].sum())}</h2></div>", unsafe_allow_html=True)

st.markdown("<br>", unsafe_allow_html=True)

# المخطط الأول: تحديد ارتفاع معقول ومتناسق مع التموضع الداكن للثيم
st.markdown("### 📊 نسب رضا الطلاب الإيجابية حسب الدكتور")
chart_df = df[["doctor_name", "positive_ratio_pct"]].copy()
chart_df = chart_df.sort_values("positive_ratio_pct", ascending=False).head(20)

fig_doc = px.bar(
    chart_df,
    x="doctor_name",
    y="positive_ratio_pct",
    labels={"doctor_name": "عضو هيئة التدريس", "positive_ratio_pct": "نسبة الرضا (%)"},
    color="positive_ratio_pct",
    color_continuous_scale="Blues",
    height=320  # تم تصغير الارتفاع ليكون مريح وواضح بالشاشة
)
fig_doc.update_layout(
    template="plotly_dark",
    paper_bgcolor="rgba(0,0,0,0)",
    plot_bgcolor="rgba(0,0,0,0)",
    yaxis_range=[0, 105],
    showlegend=False,
    font=dict(family="Cairo", size=12),
    margin=dict(l=40, r=40, t=20, b=40)
)
st.plotly_chart(fig_doc, use_container_width=True)

st.markdown("<br>", unsafe_allow_html=True)

# المخططات الفرعية جنبًا إلى جنب بـ ارتفاع متطابق ومثالي جداً
col_a, col_b = st.columns(2)
with col_a:
    st.markdown("### 🏛️ متوسط الرضا حسب الكلية")
    college_summary = df.groupby("college", as_index=False)["positive_ratio_pct"].mean().sort_values("positive_ratio_pct", ascending=False)
    
    fig_coll = px.bar(
        college_summary,
        x="college",
        y="positive_ratio_pct",
        labels={"college": "الكلية", "positive_ratio_pct": "متوسط الرضا (%)"},
        color="positive_ratio_pct",
        color_continuous_scale="Purples",
        height=300  # حجم أصغر ومتناسق هندسياً
    )
    fig_coll.update_layout(
        template="plotly_dark",
        paper_bgcolor="rgba(0,0,0,0)",
        plot_bgcolor="rgba(0,0,0,0)",
        yaxis_range=[0, 105],
        showlegend=False,
        font=dict(family="Cairo", size=12),
        margin=dict(l=40, r=40, t=20, b=40)
    )
    st.plotly_chart(fig_coll, use_container_width=True)

with col_b:
    st.markdown("### 🧠 توزيع فئات التميز المكتشفة")
    category_counts = df["excellence_category"].value_counts().reset_index()
    category_counts.columns = ["excellence_category", "count"]
    
    fig_cat = px.bar(
        category_counts,
        x="excellence_category",
        y="count",
        labels={"excellence_category": "فئة التميز", "count": "عدد أعضاء التدريس"},
        color="count",
        color_continuous_scale="Teal",
        height=300  # حجم أصغر ومتناسق هندسياً
    )
    fig_cat.update_layout(
        template="plotly_dark",
        paper_bgcolor="rgba(0,0,0,0)",
        plot_bgcolor="rgba(0,0,0,0)",
        showlegend=False,
        font=dict(family="Cairo", size=12),
        margin=dict(l=40, r=40, t=20, b=40)
    )
    st.plotly_chart(fig_cat, use_container_width=True)

st.markdown("---")

st.markdown("### ⭐ ترشيح تلقائي للدكاترة المتميزين")
shortlist = df[(df["positive_ratio_pct"] >= 75) & (df["excellence_category"] != "عام")].copy()
shortlist = shortlist.sort_values(["positive_ratio_pct", "review_count"], ascending=False)

if shortlist.empty:
    st.info("لا توجد مرشحين حالياً يتجاوزون حد التميز المحدد.")
else:
    for _, row in shortlist.head(10).iterrows():
        st.markdown(f"""
        <div class="doctor-box">
            <h4 style="margin: 0 0 5px 0; color: #1d7bb6;">🏅 {row['doctor_name']}</h4>
            <p style="margin: 3px 0; color: #ccc; font-size:0.95rem;"><b>الكلية:</b> {row['college'] or 'غير محدد'} | <b>القسم:</b> {row['department'] or 'غير محدد'}</p>
            <p style="margin: 3px 0; color: #81c784; font-size:0.95rem;"><b>نسبة الرضا الإيجابية:</b> {row['positive_ratio_pct']}% | <b>التقييم المتوسط:</b> {row['avg_rating']:.2f} ⭐ | <b>فئة التميز بالـ AI:</b> {row['excellence_category']}</p>
        </div>
        """, unsafe_allow_html=True)
        if isinstance(row.get("keywords"), list) and row["keywords"]:
            st.markdown("<p style='font-size:0.85rem; color:#aaa; margin-top:-5px; margin-bottom:15px;'>🔍 <b>الكلمات الدلالية:</b> " + ", ".join(row["keywords"][:6]) + "</p>", unsafe_allow_html=True)

st.markdown("---")

st.markdown("### 💬 مساعد ذكي لأسئلة الطلاب")
subject_name = st.text_input("أدخل اسم المادة أو الكود للبحث والتوصية الدقيقة:", value="تراكيب البيانات")
if st.button("اسأل المساعد الذكي 🧠"):
    with st.spinner("جاري تحليل التقييمات وإنتاج التوصية..."):
        answer = get_doctor_recommendation(subject_name)
        st.success(answer)