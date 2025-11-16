import pymupdf4llm
from langchain.text_splitter import MarkdownTextSplitter
md_text = pymupdf4llm.to_markdown("/Users/hnparis8/Sites/omk_h2ptm/files/original/287b532e1e4ecf0b393458efe5c8d00f92d1ca1c.pdf")

splitter = MarkdownTextSplitter(chunk_size=40, chunk_overlap=0)
splitter.create_documents([md_text])

# write markdown string to some file
output = open("/Users/hnparis8/Sites/omk_h2ptm/files/md/test.md", "w")
# output.write(md_text)
output.write(md_text)
output.close()
