from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker

DATABASE_URL = ""

engine = create_engine(DATABASE_URL)
SessionLocal = sessionmaker(bind=engine, autoflush=False, autocommit=False)

models.py
from sqlalchemy import Column, String, Integer, DateTime, JSON
from sqlalchemy.ext.declarative import declarative_base

Base = declarative_base()

class RequestLog(Base):
    __tablename__ = 'request_logs'
    id = Column(Integer, primary_key=True)
    ip = Column(String, index=True)
    timestamp = Column(DateTime)
    response_data = Column(JSON, nullable=True)
    from_cache = Column(Integer)  # 1 是，0 否
    error = Column(String, nullable=True)

class IpCache(Base):
    __tablename__ = 'ip_cache'
    ip = Column(String, primary_key=True)
    cached_at = Column(DateTime)
    response_data = Column(JSON)
